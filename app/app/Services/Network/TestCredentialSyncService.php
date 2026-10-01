<?php

namespace App\Services\Network;

use App\Enums\TestOnuConfigMethod;
use App\Enums\TestOnuMode;
use App\Enums\TestOnuWanMode;
use App\Models\Customer;
use App\Models\NetworkProfileGroup;
use App\Models\OltDevice;
use App\Models\PppPackage;
use App\Models\TestOnuWanConfig;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * v0.23.5 Bagian D — TESTING ONLY. Setiap method di sini menolak keras
 * customer selain `is_test_fixture=true` (lihat assertTestFixture()) —
 * ini BUKAN fitur produksi, murni alat bantu verifikasi end-to-end
 * cluster OMCI sebelum aktivasi customer asli dimulai.
 *
 * **ZTE C300-ONLY.** OltSidecarClient::activateOnu()/fixOnuVlan()/
 * deleteOnu()/addPppoe() semuanya memanggil operation Python yang cuma
 * diimplementasikan di `zte_c300.py` — tidak ada padanan di
 * `hsgq_e04id.py`/`hsgq_g02id.py`. Dicek eksplisit (bukan diasumsikan)
 * sebelum menambah parameter tcont_profile di bawah: HSGQ-G02ID PUNYA
 * konsep TCONT (`tcont <N> dba-profile-id <N>`, lihat
 * docs/omci/hsgq-g02id-cli-reference.md), TAPI arsitekturnya beda total
 * dari ZTE — bukan nama-profile-string-per-ONU langsung seperti ZTE
 * (`tcont 1 profile <nama>` di context `interface gpon-onu_...`),
 * melainkan `ont-lineprofile gpon profile-id <N>` sebagai TEMPLATE
 * global (tcont+gem+vlan mapping sekaligus), lalu ONU individual
 * di-assign ke satu line-profile itu — tidak compatible dengan desain
 * parameter string tunggal per-ONU di bawah tanpa investigasi CLI
 * terpisah untuk vendor itu. HSGQ-E04ID: TIDAK ADA sebutan tcont/
 * bandwidth sama sekali di dokumentasi referensi kita — field `profile`
 * di situ ditandai eksplisit "Belum diuji". Jangan menebak command HSGQ
 * — kalau TestCredentialSyncService perlu dipakai untuk OLT HSGQ nanti,
 * itu investigasi CLI terpisah, bukan generalisasi dari kelas ini.
 *
 * Orkestrasi (updatePackage()) sengaja MEMANGGIL ULANG method
 * OltSidecarClient individual yang SUDAH TERUJI (deleteOnu/activateOnu/
 * addPppoe), dengan verifikasi eksplisit di antara tiap langkah — BUKAN
 * satu operation sidecar baru yang menggabungkan semuanya dalam satu
 * sesi telnet. Keputusan desain deliberate: setiap langkah individual
 * sudah dibuktikan bekerja terpisah (migrasi VLAN 10->111 ONU_1),
 * menggabungkannya jadi satu operation baru tanpa titik verifikasi di
 * antaranya akan memperkenalkan risiko yang belum teruji, padahal
 * risikonya bisa dihindari sepenuhnya dengan reuse yang sudah ada.
 *
 * `Customer::test_onu_metadata` (bukan `onu_registries.customer_id` —
 * itu desain terkunci v0.23.4, murni cache read-only, sengaja tidak
 * pernah tahu tentang Customer) adalah satu-satunya sumber mapping
 * customer -> ONU fisik: `{olt_device_id, pon_interface, onu_id, sn,
 * onu_type, vlan_mgmt, vlan_bridge, tcont_profile, traffic_profile}`.
 * `tcont_profile`/`traffic_profile` WAJIB diisi di metadata (bukan
 * hardcode di kode ini, bukan pula fallback diam-diam) — nama profile
 * ini murni konfigurasi OLT-native (dibuat manual di OLT, tidak
 * dimodelkan di BOSS App manapun), jadi satu-satunya sumber kebenaran
 * yang jujur adalah apa yang benar-benar dipakai saat provisioning ONU
 * itu pertama kali, dicatat eksplisit di metadata-nya sendiri.
 */
class TestCredentialSyncService
{
    /**
     * Jumlah percobaan poll status 'working' setelah activate_onu, dan
     * jeda antar-percobaan. v0.23.5 amendment — bug nyata ditemukan:
     * versi awal membaca onu_state SEKALI, SEGERA setelah activate_onu
     * sukses, tanpa jeda — OMCI butuh beberapa detik untuk transisi
     * status, jadi verifikasi gagal padahal ONU genuinely working
     * beberapa detik kemudian (dikonfirmasi manual: baca ulang read-only
     * setelah kegagalan menunjukkan status sudah 'working'). Poll
     * pendek berulang (bukan satu sleep panjang) supaya kasus cepat
     * tidak menunggu penuh 30 detik untuk apa-apa.
     */
    private const WORKING_POLL_ATTEMPTS = 6;

    private const WORKING_POLL_DELAY_SECONDS = 5;

    /**
     * VLAN 9 (remote management: tr069-mgmt + ip-host2) SELALU termasuk di
     * attached VLANs dan TIDAK bisa dihapus — di-enforce DI SINI (service),
     * bukan hanya di UI (instruksi eksplisit Agung v0.23.5 Opsi B). Lihat
     * docs/omci/onu-test-ui-design.md.
     */
    public const MANDATORY_ATTACHED_VLAN = 9;

    /**
     * Password PPPoE KONSTANTA SISTEM untuk fixture OMCI test — seragam
     * untuk semua customer test (keputusan Agung OPSI a). TIDAK disimpan
     * per-customer; baris `pppoe ... password <ini>` di OLT + radcheck
     * sama-sama memakai nilai ini. (Catatan: konvensi password==username
     * di RadcheckWriterService itu untuk pelanggan MIGRASI nyata v0.8.4,
     * BUKAN alur OMCI-test ini.)
     */
    public const PPPOE_PASSWORD = 'wifijadipasti';

    public function __construct(
        private readonly OltSidecarClient $sidecar,
        private readonly RadcheckWriterService $radcheck,
    ) {}

    private function assertTestFixture(Customer $customer): void
    {
        if (! $customer->is_test_fixture) {
            throw new RuntimeException(
                "TestCredentialSyncService hanya boleh dipanggil untuk customer is_test_fixture=true (customer #{$customer->id} bukan test fixture)."
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requireMetadata(Customer $customer): array
    {
        $meta = $customer->test_onu_metadata;
        if (! is_array($meta) || ! isset(
            $meta['olt_device_id'], $meta['pon_interface'], $meta['onu_id'], $meta['sn'],
            $meta['tcont_profile'], $meta['traffic_profile'],
        )) {
            throw new RuntimeException(
                "Customer #{$customer->id} tidak punya test_onu_metadata lengkap (olt_device_id/pon_interface/onu_id/sn/tcont_profile/traffic_profile wajib ada — tcont_profile/traffic_profile TIDAK di-fallback ke nilai hardcode, harus diisi eksplisit)."
            );
        }

        return $meta;
    }

    /**
     * Ekstrak nomor VLAN dari nama interface RouterOS (mis.
     * "vlan10-PPPoE" -> 10, "vlan111-PPPoE-10Mbps-Loyalis" -> 111).
     * NetworkProfileGroup sendiri TIDAK punya kolom VLAN eksplisit — VLAN
     * hanya tersimpan tersirat di `interface_name` (dan di router itu
     * sendiri). Ini satu-satunya sumber untuk menentukan VLAN target
     * tanpa menebak/hardcode.
     */
    private function extractVlanFromInterfaceName(string $interfaceName): int
    {
        if (! preg_match('/^vlan(\d+)-/', $interfaceName, $m)) {
            throw new RuntimeException("Tidak bisa mengekstrak nomor VLAN dari interface_name '{$interfaceName}' (format tidak dikenal).");
        }

        return (int) $m[1];
    }

    /**
     * Poll `onu_state` berulang (maks WORKING_POLL_ATTEMPTS kali, jeda
     * WORKING_POLL_DELAY_SECONDS detik) sampai ONU menunjukkan status
     * 'working', atau menyerah (return false) setelah semua percobaan
     * habis. Percobaan pertama TANPA jeda (device kadang sudah working
     * seketika) — jeda hanya sebelum percobaan ke-2 dst.
     */
    private function pollForWorkingState(OltDevice $olt, string $ponInterface, int $onuId): bool
    {
        for ($attempt = 1; $attempt <= self::WORKING_POLL_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                Sleep::for(self::WORKING_POLL_DELAY_SECONDS)->seconds();
            }

            $state = $this->sidecar->read($olt, 'onu_state', ['onu' => "gpon-olt_{$ponInterface}"]);
            foreach ($state['data']['lines'] ?? [] as $line) {
                if (str_contains($line, "{$ponInterface}:{$onuId} ") && str_contains($line, 'working')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Ganti paket/VLAN PPPoE ONU test ke Grup Profil lain. Username/
     * password PPPoE TIDAK berubah (tetap {cid}@ppp.bajastu.id / password
     * yang sudah tersimpan di radcheck) — hanya VLAN dan Framed-Pool yang
     * berpindah mengikuti Grup Profil baru.
     *
     * Urutan (SAMA PERSIS pola yang sudah terbukti untuk migrasi VLAN
     * 10->111 ONU_1, dieksekusi manual sebelumnya): delete_onu -> verify
     * clean (uncfg/unknown) -> activate_onu (VLAN baru, SN/type/nama
     * SAMA) -> verify online (working) -> add_pppoe (kredensial SAMA) ->
     * update radcheck Framed-Pool kalau Grup Profil baru punya pool
     * berbeda.
     *
     * @return array<string, mixed>
     */
    public function updatePackage(Customer $customer, int $networkProfileGroupId, string $pppoePassword): array
    {
        $this->assertTestFixture($customer);
        $meta = $this->requireMetadata($customer);

        $group = NetworkProfileGroup::withoutGlobalScopes()->with('customerIpPool')->findOrFail($networkProfileGroupId);
        if ($group->customerIpPool === null) {
            throw new RuntimeException("Network Profile Group #{$networkProfileGroupId} tidak punya CustomerIpPool terkait.");
        }

        $olt = OltDevice::withoutGlobalScopes()->findOrFail($meta['olt_device_id']);
        $ponInterface = $meta['pon_interface'];
        $onuId = (int) $meta['onu_id'];
        $sn = $meta['sn'];
        $onuType = $meta['onu_type'] ?? 'M12X5G_XPON';
        $onuName = $meta['onu_name'] ?? "TEST OMCI - {$customer->cid}";
        $vlanMgmt = (int) ($meta['vlan_mgmt'] ?? 9);
        $vlanBridge = (int) ($meta['vlan_bridge'] ?? 172);
        // TIDAK di-fallback ke nilai hardcode — requireMetadata() sudah
        // memastikan keduanya ada di metadata sebelum sampai sini.
        $tcontProfile = $meta['tcont_profile'];
        $trafficProfile = $meta['traffic_profile'];
        $newVlanPppoe = $this->extractVlanFromInterfaceName($group->interface_name);
        $username = "{$customer->cid}@ppp.bajastu.id";

        // Langkah 1-5 (delete -> verify bersih -> activate -> verify working
        // -> add_pppoe) diorkestrasi helper bersama reactivateOnuWithPppoe().
        $commands = $this->reactivateOnuWithPppoe($olt, [
            'pon_interface' => $ponInterface,
            'onu_id' => $onuId,
            'sn' => $sn,
            'onu_type' => $onuType,
            'name' => $onuName,
            'tcont_profile' => $tcontProfile,
            'traffic_profile' => $trafficProfile,
            'vlan_pppoe' => $newVlanPppoe,
            'vlan_mgmt' => $vlanMgmt,
            'vlan_bridge' => $vlanBridge,
            'extra_flow_vlans' => [],
            'username' => $username,
            'password' => $pppoePassword,
        ]);

        // 6. Update radcheck Framed-Pool ke pool Grup Profil baru.
        $framedPool = $group->customerIpPool->routerOsPoolName();
        $this->radcheck->write($username, $pppoePassword, $framedPool);

        // 7. Simpan metadata VLAN baru untuk pemanggilan berikutnya.
        $customer->update([
            'test_onu_metadata' => array_merge($meta, [
                'current_vlan_pppoe' => $newVlanPppoe,
                'network_profile_group_id' => $networkProfileGroupId,
            ]),
        ]);

        return [
            'vlan_pppoe' => $newVlanPppoe,
            'framed_pool' => $framedPool,
            'username' => $username,
            'commands_applied' => $commands,
        ];
    }

    /**
     * v0.23.5 (Opsi B) — terapkan konfigurasi WAN test dari tabel
     * `test_onu_wan_configs` (model "1 WAN aktif + Attached VLANs"). HANYA
     * kombinasi `config_method=omci` + `wan_mode=pppoe` yang flow
     * eksekusinya diimplementasikan v1; kombinasi lain ditolak jelas
     * ("belum didukung"), BUKAN dieksekusi diam-diam. `onu_mode` wajib
     * `routing` (bridging belum terbukti).
     *
     * Attached VLANs (pivot) dipetakan ke template OMCI terbukti:
     * - VLAN 9 (mgmt) WAJIB ada — MANDATORY_ATTACHED_VLAN, di-enforce di
     *   sini (bukan hanya UI). Mengisi slot `vlan_mgmt`.
     * - 1 VLAN attached non-(9/pppoe) pertama mengisi slot `vlan_bridge`
     *   (service-port 12), sisanya jadi baris `flow` permission tambahan
     *   (`extra_flow_vlans`) — izin lewat tanpa service-port sendiri.
     *
     * @return array<string, mixed>
     */
    public function applyWanConfig(Customer $customer): array
    {
        $this->assertTestFixture($customer);
        $meta = $this->requireMetadata($customer);

        $config = TestOnuWanConfig::where('customer_id', $customer->id)
            ->with('attachedVlans')
            ->first();
        if ($config === null) {
            throw new RuntimeException("Customer #{$customer->id} belum punya konfigurasi WAN test (test_onu_wan_configs).");
        }

        // v1: hanya routing + pppoe + omci.
        if ($config->onu_mode !== TestOnuMode::Routing) {
            throw new RuntimeException("ONU mode '{$config->onu_mode->value}' belum didukung (v1 hanya Routing).");
        }
        if ($config->wan_mode !== TestOnuWanMode::Pppoe) {
            throw new RuntimeException("WAN mode '{$config->wan_mode->value}' belum didukung (v1 hanya PPPoE).");
        }
        if ($config->config_method === TestOnuConfigMethod::Tr069) {
            return $this->applyTr069WanConfig($customer, $config);
        }

        // --- config_method = OMCI, wan_mode = PPPoE ---
        // Paket/VLAN/username/password DIDERIVE LIVE dari customer (OPSI a,
        // keputusan Agung) — tidak ada lagi yang disimpan di
        // test_onu_wan_configs. Satu sumber kebenaran: customers.ppp_package_id
        // (diubah lewat Daftar Pelanggan), customers.cid, dan konstanta
        // PPPOE_PASSWORD.
        $live = $this->deriveLiveWanParams($customer);
        if (! $live['has_vlan_package']) {
            throw new RuntimeException($live['message']);
        }
        $vlanPppoe = $live['vlan_pppoe'];
        $framedPool = $live['framed_pool'];
        $username = $live['username'];
        $password = $live['password'];

        // Slot bridge (service-port 12) = VLAN bridge FISIK ONU dari metadata
        // (172 untuk ONU_1/ONU_2) — deterministik, bukan dari urutan attached.
        $vlanMgmt = self::MANDATORY_ATTACHED_VLAN;
        $vlanBridge = (int) ($meta['vlan_bridge'] ?? 172);

        // Attached VLANs: pastikan VLAN 9 (mandatory) ikut. 3 slot basis
        // (mgmt/pppoe/bridge) sudah punya service-port+flow sendiri; attached
        // di LUAR ketiganya jadi baris flow PERMISSION tambahan.
        $attached = $config->attachedVlans->pluck('vlan_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        if (! in_array(self::MANDATORY_ATTACHED_VLAN, $attached, true)) {
            $attached[] = self::MANDATORY_ATTACHED_VLAN; // enforce di service, bukan hanya UI
        }
        sort($attached);
        $baseSlots = [$vlanMgmt, $vlanPppoe, $vlanBridge];
        $extraFlowVlans = array_values(array_filter(
            $attached,
            fn ($v) => ! in_array($v, $baseSlots, true),
        ));

        $olt = OltDevice::withoutGlobalScopes()->findOrFail($meta['olt_device_id']);
        $commands = $this->reactivateOnuWithPppoe($olt, [
            'pon_interface' => $meta['pon_interface'],
            'onu_id' => (int) $meta['onu_id'],
            'sn' => $meta['sn'],
            'onu_type' => $meta['onu_type'] ?? 'M12X5G_XPON',
            'name' => $meta['onu_name'] ?? "TEST OMCI - {$customer->cid}",
            'tcont_profile' => $meta['tcont_profile'],
            'traffic_profile' => $meta['traffic_profile'],
            'vlan_pppoe' => $vlanPppoe,
            'vlan_mgmt' => $vlanMgmt,
            'vlan_bridge' => $vlanBridge,
            'extra_flow_vlans' => $extraFlowVlans,
            'username' => $username,
            'password' => $password,
        ]);

        $this->radcheck->write($username, $password, $framedPool);

        $customer->update([
            'test_onu_metadata' => array_merge($meta, [
                'current_vlan_pppoe' => $vlanPppoe,
                'network_profile_group_id' => $live['group_id'],
            ]),
        ]);

        return [
            'vlan_pppoe' => $vlanPppoe,
            'vlan_bridge' => $vlanBridge,
            'extra_flow_vlans' => $extraFlowVlans,
            'attached_vlans' => $attached,
            'framed_pool' => $framedPool,
            'username' => $username,
            'commands_applied' => $commands,
        ];
    }

    /**
     * Derive LIVE parameter WAN dari customer (OPSI a) — satu sumber
     * kebenaran, tidak ada yang disimpan di test_onu_wan_configs:
     * - Paket/VLAN/Framed-Pool: customers.ppp_package_id -> NetworkProfileGroup
     *   (interface_name -> VLAN via extractVlanFromInterfaceName) +
     *   customerIpPool.routerOsPoolName().
     * - username: {customers.cid}@ppp.bajastu.id.
     * - password: konstanta PPPOE_PASSWORD.
     *
     * Kalau customer belum punya paket ber-VLAN: has_vlan_package=false +
     * pesan ramah (panel tampilkan, applyWanConfig throw). Dipakai BERSAMA
     * oleh panel (display read-only) dan applyWanConfig (sumber apply) —
     * dijamin konsisten.
     *
     * @return array<string, mixed>
     */
    public function deriveLiveWanParams(Customer $customer): array
    {
        $username = $customer->cid ? "{$customer->cid}@ppp.bajastu.id" : null;
        $password = self::PPPOE_PASSWORD;

        $package = $customer->ppp_package_id
            ? PppPackage::withoutGlobalScopes()->with('networkProfileGroup.customerIpPool')->find($customer->ppp_package_id)
            : null;
        $group = $package?->networkProfileGroup;

        $hasVlan = $group !== null
            && $group->interface_name !== null
            && preg_match('/^vlan(\d+)-/', $group->interface_name) === 1
            && $group->customerIpPool !== null;

        if (! $hasVlan) {
            return [
                'has_vlan_package' => false,
                'message' => 'Pelanggan ini belum punya paket dengan VLAN, atur dulu di Daftar Pelanggan.',
                'package_name' => $package?->name,
                'vlan_pppoe' => null,
                'framed_pool' => null,
                'username' => $username,
                'password' => $password,
                'group_id' => null,
            ];
        }

        return [
            'has_vlan_package' => true,
            'message' => null,
            'package_name' => $package->name,
            'vlan_pppoe' => $this->extractVlanFromInterfaceName($group->interface_name),
            'framed_pool' => $group->customerIpPool->routerOsPoolName(),
            'username' => $username,
            'password' => $password,
            'group_id' => $group->id,
        ];
    }

    /**
     * v0.23.5 (Opsi B) — jalur config_method=tr069. Rencana: reuse
     * mekanisme RemoteWanConfig/GenieACS existing (pola Test-1/Test-2),
     * BUKAN baris pppoe OMCI. BELUM diimplementasikan di sesi ini
     * (instruksi Bagian 1: "PPPoE+OMCI saja aktif") — ditolak jelas, tidak
     * dieksekusi diam-diam.
     *
     * @return array<string, mixed>
     */
    private function applyTr069WanConfig(Customer $customer, TestOnuWanConfig $config): array
    {
        throw new RuntimeException('config_method=tr069 belum diimplementasikan (v1 hanya OMCI). Jalur ini akan reuse RemoteWanConfig/GenieACS existing.');
    }

    /**
     * Helper bersama updatePackage()/applyWanConfig(): delete -> verify
     * bersih -> activate (dengan extra_flow_vlans untuk attached VLANs) ->
     * verify working -> add_pppoe. STOP (throw) di langkah mana pun yang
     * gagal — tidak pernah lanjut ke langkah berikutnya setelah kegagalan.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, int|null> ringkasan commands_applied
     */
    private function reactivateOnuWithPppoe(OltDevice $olt, array $p): array
    {
        $ponInterface = $p['pon_interface'];
        $onuId = (int) $p['onu_id'];

        // 1. Hapus registrasi lama.
        $delete = $this->sidecar->deleteOnu($olt, ['pon_interface' => $ponInterface, 'onu_id' => $onuId]);
        if (! ($delete['success'] ?? false)) {
            throw new RuntimeException('delete_onu gagal: '.($delete['device_message'] ?? $delete['error'] ?? 'unknown'));
        }

        // 2. Verifikasi bersih (ID tidak lagi muncul di onu_state).
        $state = $this->sidecar->read($olt, 'onu_state', ['onu' => "gpon-olt_{$ponInterface}"]);
        if (! ($state['success'] ?? false)) {
            throw new RuntimeException('Verifikasi onu_state setelah delete gagal: '.($state['device_message'] ?? 'unknown'));
        }
        foreach ($state['data']['lines'] ?? [] as $line) {
            if (str_starts_with(trim($line), "{$ponInterface}:{$onuId} ")) {
                throw new RuntimeException("ONU {$onuId} masih terdaftar setelah delete_onu — tidak bersih, STOP.");
            }
        }

        // 3. Registrasi ulang (VLAN + attached flow VLANs).
        $activate = $this->sidecar->activateOnu($olt, [
            'pon_interface' => $ponInterface,
            'onu_id' => $onuId,
            'sn' => $p['sn'],
            'onu_type' => $p['onu_type'],
            'name' => $p['name'],
            'tcont_profile' => $p['tcont_profile'],
            'traffic_profile' => $p['traffic_profile'],
            'vlan_pppoe' => $p['vlan_pppoe'],
            'vlan_mgmt' => $p['vlan_mgmt'],
            'vlan_bridge' => $p['vlan_bridge'],
            'extra_flow_vlans' => $p['extra_flow_vlans'] ?? [],
        ]);
        if (! ($activate['success'] ?? false)) {
            throw new RuntimeException('activate_onu gagal: '.($activate['device_message'] ?? $activate['error'] ?? 'unknown'));
        }

        // 4. Verifikasi online (working) — POLL berjeda.
        if (! $this->pollForWorkingState($olt, $ponInterface, $onuId)) {
            throw new RuntimeException("ONU {$onuId} tidak menunjukkan status 'working' setelah activate_onu (dicoba ".self::WORKING_POLL_ATTEMPTS.'x, jeda '.self::WORKING_POLL_DELAY_SECONDS.'s) — STOP, tidak lanjut ke pppoe.');
        }

        // 5. Tambah baris pppoe.
        $addPppoe = $this->sidecar->addPppoe($olt, [
            'pon_interface' => $ponInterface,
            'onu_id' => $onuId,
            'host_id' => 1,
            'username' => $p['username'],
            'password' => $p['password'],
            'nat_enabled' => true,
        ]);
        if (! ($addPppoe['success'] ?? false)) {
            throw new RuntimeException('add_pppoe gagal: '.($addPppoe['device_message'] ?? $addPppoe['error'] ?? 'unknown'));
        }

        return [
            'delete_onu' => $delete['data']['commands_applied'] ?? null,
            'activate_onu' => $activate['data']['commands_applied'] ?? null,
            'add_pppoe' => $addPppoe['data']['commands_applied'] ?? null,
        ];
    }
}
