<?php

namespace App\Services\Network\Provisioning\Olt;

use App\Enums\PonMode;
use App\Enums\ProvisioningAdapterLayer;
use App\Models\OltDevice;
use App\Services\Network\OltSidecarClient;
use App\Services\Network\Provisioning\AdapterCapabilities;
use App\Services\Network\Provisioning\Contracts\OltProvisioningAdapter;
use App\Services\Network\Provisioning\Exceptions\AdapterNotYetImplemented;
use RuntimeException;

/**
 * OLT adapter ZTE C300 (GPON) — MEMBUNGKUS logika aktivasi OMCI yang SUDAH
 * PROVEN dari v0.23.5 (sidecar `zte_c300.OPERATIONS_WRITE['activate_onu']` +
 * OltSidecarClient::activateOnu) ke interface OltProvisioningAdapter. BUKAN
 * logika baru — wrapper tipis.
 *
 * Temuan v0.23.8.2b (diinvestigasi, bukan diasumsikan — lihat laporan):
 *  - NAMING: ZTE TIDAK punya command naming terpisah. Nama di-set INLINE
 *    sebagai `name <NAMA>` di awal blok `interface gpon-onu_...` DI DALAM
 *    activate_onu (pushWanInternetOmci). Jadi applyNaming() = NO-OP
 *    terdokumentasi (nama sudah ter-set saat push), bukan error/skip senyap.
 *  - SAVE: ZTE C300 auto-write. Command `wr` DITOLAK device
 *    ("%Error 20200: Invalid command", riset v0.23.2/v0.23.5) — tidak ada
 *    command save terverifikasi. saveConfig() = NO-OP terdokumentasi.
 *  - ADMIT (alokasi onu-id + registrasi): registrasi ONU (`onu <id> type ..
 *    sn ..`) sudah jadi command PERTAMA di activate_onu — BUKAN langkah
 *    terpisah. Alokasi onu-id otomatis ("pilih ID kosong terkecil") BUKAN op
 *    terverifikasi: `show gpon onu next-available` DITOLAK device (v0.23.2),
 *    indeks `onu_uncfg_list` hanya USULAN (bukan slot kosong terjamin). Jadi
 *    `onu_id` disuplai pemanggil/orkestrator (v0.23.8.5, dari uncfg/manual) —
 *    admit() belum bisa dibungkus dari kode proven, dilempar eksplisit.
 */
final class ZteC300OltAdapter implements OltProvisioningAdapter
{
    /** Param terstruktur wajib activate_onu (sidecar yang memvalidasi nilainya). */
    private const REQUIRED_PUSH_KEYS = [
        'pon_interface', 'onu_id', 'sn', 'onu_type', 'name',
        'tcont_profile', 'traffic_profile', 'vlan_pppoe', 'vlan_mgmt', 'vlan_bridge',
    ];

    public function __construct(private readonly string $vendorKey = 'zte_c300') {}

    public function vendorKey(): string
    {
        return $this->vendorKey;
    }

    public function layer(): ProvisioningAdapterLayer
    {
        return ProvisioningAdapterLayer::Olt;
    }

    public function transport(): string
    {
        return 'omci_cli';
    }

    public function isSupported(): bool
    {
        return true;
    }

    public function ponMode(): PonMode
    {
        return PonMode::Gpon;
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            operations: [
                'admit' => true,          // registrasi bundel di activate_onu; alokasi onu-id manual/orkestrator
                'wan_internet_omci' => true,
                'naming' => true,         // inline di activate_onu (bukan command terpisah)
                'save' => false,          // auto-write
            ],
            wanInternetVia: 'omci',
        );
    }

    public function admit(OltDevice $olt, array $context = []): array
    {
        throw new AdapterNotYetImplemented(
            'ZTE C300: registrasi ONU sudah jadi bagian activate_onu (pushWanInternetOmci) yang membawa '
            .'onu_id dari pemanggil; alokasi onu-id otomatis BELUM ada op sidecar terverifikasi '
            .'(next-available ditolak device, indeks uncfg hanya usulan) — di-wire orkestrator v0.23.8.5.'
        );
    }

    /**
     * WAN internet via OMCI — bungkus activate_onu (tcont/gemport/service-port/
     * flow/security-mgmt/pppoe + `name` inline) yang sudah proven. `$spec` =
     * param terstruktur activate_onu (sidecar merakit+memvalidasi command dari
     * template internal; adapter TIDAK pernah menyusun string CLI).
     */
    public function pushWanInternetOmci(OltDevice $olt, array $spec): array
    {
        foreach (self::REQUIRED_PUSH_KEYS as $key) {
            if (! array_key_exists($key, $spec)) {
                throw new RuntimeException("ZteC300OltAdapter::pushWanInternetOmci butuh spec[\"{$key}\"].");
            }
        }

        $params = array_intersect_key(
            $spec,
            array_flip([...self::REQUIRED_PUSH_KEYS, 'extra_flow_vlans']),
        );

        return $this->sidecar()->activateOnu($olt, $params, $spec['requested_by'] ?? null);
    }

    /**
     * NO-OP terdokumentasi — nama ONU ZTE di-set inline di activate_onu
     * (`name <NAMA>`), bukan command naming terpisah seperti G02ID/E04ID.
     */
    public function applyNaming(OltDevice $olt, array $spec): array
    {
        return [
            'named_inline' => true,
            'note' => 'ZTE C300: nama di-set via activate_onu (name <...>), bukan command terpisah.',
        ];
    }

    /**
     * NO-OP terdokumentasi — ZTE C300 auto-write; command `wr` ditolak device
     * (tidak ada command save terverifikasi, riset v0.23.2/v0.23.5).
     */
    public function saveConfig(OltDevice $olt): array
    {
        return [
            'saved' => true,
            'auto_write' => true,
            'note' => 'ZTE C300 auto-write ke running-config; tidak ada command save (wr ditolak device).',
        ];
    }

    private function sidecar(): OltSidecarClient
    {
        return app(OltSidecarClient::class);
    }
}
