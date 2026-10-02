<?php

namespace App\Services\Network;

use App\Models\Customer;
use App\Models\OltDevice;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * v0.23.6 — Aktivasi G02ID dengan arsitektur final:
 *   WAN  -> TR-069 (device-driven; CPE CT-COM membentuk WAN-nya sendiri,
 *           BOSS cuma menyediakan kredensial RADIUS + menunggu Connected).
 *   NAMA -> OMCI-direct (`ont setting <id> name "Nama - CID"`), lewat sidecar
 *           set_ont_naming (TERUJI aman produksi, nol gangguan).
 *
 * Alur (lihat activate()): (3a) tulis radcheck -> (3b) poll WAN PPPoE internet
 * sampai Connected via GenieACS -> (3c) resolve onu_id dari SN + set nama OMCI.
 *
 * PRASYARAT (BUKAN tanggung jawab service ini — sengaja): `Customer` sudah ada
 * dan `ppp_package_id`-nya sudah diset ke paket ber-VLAN (dari situ VLAN/
 * Framed-Pool/username diturunkan). Service ini TIDAK memfabrikasi customer
 * dari nol (butuh data pelanggan nyata — tanggung jawab pemanggil/UI).
 *
 * TIDAK ADA operasi `ont wanconfig add` di mana pun di sini — sudah terbukti
 * tidak viable di fleet G02ID (lihat docs/omci/hsgq-g02id-cli-reference.md
 * "KESIMPULAN ARSITEKTUR FINAL v0.23.6").
 */
class G02idActivationService
{
    /** Batas poll WAN Connected — disetarakan ke siklus cache GenieACS (~5,5 mnt). */
    public const POLL_MAX_SECONDS = 300;

    public const POLL_INTERVAL_SECONDS = 30;

    /** Batas panjang nama ONT (sinkron dgn _NAMING_MAX_LEN sidecar G02ID). */
    public const ONT_NAME_MAX_LEN = 40;

    public function __construct(
        private readonly RadcheckWriterService $radcheck,
        private readonly TestCredentialSyncService $credentials,
        private readonly GenieAcsClientService $genieacs,
        private readonly OltSidecarClient $sidecar,
    ) {}

    /**
     * (3a) Tulis radcheck/radreply untuk PPPoE pelanggan — reuse
     * deriveLiveWanParams (username `{cid}@ppp.bajastu.id`, password konstanta,
     * Framed-Pool & VLAN dari paket) + RadcheckWriterService, pola sama ZTE.
     *
     * @return array{username: string, vlan: int, framed_pool: string}
     */
    public function writeRadcheck(Customer $customer): array
    {
        $live = $this->credentials->deriveLiveWanParams($customer);

        if (($live['has_vlan_package'] ?? false) !== true) {
            throw new RuntimeException(
                $live['message'] ?? 'Pelanggan belum punya paket ber-VLAN — atur ppp_package_id dulu.'
            );
        }

        $this->radcheck->write($live['username'], $live['password'], $live['framed_pool']);

        return [
            'username' => $live['username'],
            'vlan' => (int) $live['vlan_pppoe'],
            'framed_pool' => $live['framed_pool'],
        ];
    }

    /**
     * (3b) Poll GenieACS sampai WAN PPPoE internet (Name mengandung "INTERNET")
     * ber-ConnectionStatus "Connected", atau timeout. BUKAN infinite loop —
     * mengembalikan ['connected' => false, ...] dengan alasan jelas kalau habis
     * waktu. Memakai Sleep facade (Sleep::fake() di test, tanpa tunggu nyata).
     *
     * @return array{connected: bool, device_id: ?string, wan_status: ?string, elapsed_seconds: int, reason: ?string}
     */
    public function pollWanConnected(
        string $sn,
        int $maxSeconds = self::POLL_MAX_SECONDS,
        int $intervalSeconds = self::POLL_INTERVAL_SECONDS,
    ): array {
        $elapsed = 0;
        $lastDeviceId = null;
        $lastStatus = null;

        while (true) {
            $device = $this->findGenieAcsDevice($sn);
            if ($device !== null) {
                $lastDeviceId = $device['_id'] ?? null;
                $lastStatus = $this->internetWanStatus($device);
                if ($lastStatus !== null && strcasecmp($lastStatus, 'Connected') === 0) {
                    return [
                        'connected' => true,
                        'device_id' => $lastDeviceId,
                        'wan_status' => $lastStatus,
                        'elapsed_seconds' => $elapsed,
                        'reason' => null,
                    ];
                }
            }

            if ($elapsed >= $maxSeconds) {
                return [
                    'connected' => false,
                    'device_id' => $lastDeviceId,
                    'wan_status' => $lastStatus,
                    'elapsed_seconds' => $elapsed,
                    'reason' => $device === null
                        ? 'Device belum pernah Inform ke GenieACS dalam batas waktu.'
                        : 'WAN internet belum Connected dalam batas waktu (status terakhir: '.($lastStatus ?? 'tidak diketahui').').',
                ];
            }

            Sleep::for($intervalSeconds)->seconds();
            $elapsed += $intervalSeconds;
        }
    }

    /**
     * (3c) Resolve onu_id dari SN (sidecar, coba gpon 1/2) lalu set nama OMCI
     * "Nama - CID". Melempar RuntimeException kalau SN tak ketemu di OLT atau
     * sidecar/device menolak.
     *
     * @return array{pon: int, onu_id: int, name: string}
     */
    public function applyOmciNaming(OltDevice $oltDevice, Customer $customer, string $sn, ?int $requestedBy = null): array
    {
        $resolve = $this->sidecar->resolveOnuBySn($oltDevice, $sn, $requestedBy);
        if (($resolve['success'] ?? false) !== true) {
            throw new RuntimeException(
                'Resolve ONU gagal: '.($resolve['device_message'] ?? $resolve['error'] ?? 'tanpa pesan')
            );
        }

        $data = $resolve['data'] ?? [];
        if (($data['found'] ?? false) !== true) {
            throw new RuntimeException("SN {$sn} tidak ditemukan di OLT (gpon 1 maupun 2) — belum ter-authorize?");
        }

        $name = $this->buildOntName($customer);

        $result = $this->sidecar->setOntNaming($oltDevice, [
            'pon' => (int) $data['pon'],
            'onu_id' => (int) $data['onu_id'],
            'name' => $name,
        ], $requestedBy);

        if (($result['success'] ?? false) !== true) {
            throw new RuntimeException(
                'Set nama OMCI gagal: '.($result['device_message'] ?? $result['error'] ?? 'tanpa pesan')
            );
        }

        return ['pon' => (int) $data['pon'], 'onu_id' => (int) $data['onu_id'], 'name' => $name];
    }

    /**
     * Orkestrator penuh: radcheck -> poll Connected -> nama OMCI. Penamaan
     * HANYA dijalankan setelah WAN internet benar-benar Connected (keputusan
     * Agung: radcheck adalah langkah alami aktivasi, "Connected" tetap trigger).
     *
     * @return array<string, mixed>
     */
    public function activate(OltDevice $oltDevice, Customer $customer, string $sn, ?int $requestedBy = null): array
    {
        $radcheck = $this->writeRadcheck($customer);
        $poll = $this->pollWanConnected($sn);

        if ($poll['connected'] !== true) {
            return [
                'ok' => false,
                'stage' => 'poll_wan',
                'radcheck' => $radcheck,
                'poll' => $poll,
                'naming' => null,
            ];
        }

        $naming = $this->applyOmciNaming($oltDevice, $customer, $sn, $requestedBy);

        return [
            'ok' => true,
            'stage' => 'done',
            'radcheck' => $radcheck,
            'poll' => $poll,
            'naming' => $naming,
        ];
    }

    /** Format nama ONT "Nama - CID", dipangkas ke batas aman kalau kepanjangan. */
    public function buildOntName(Customer $customer): string
    {
        $full = trim((string) $customer->name).' - '.trim((string) $customer->cid);

        return mb_strlen($full) > self::ONT_NAME_MAX_LEN
            ? mb_substr($full, 0, self::ONT_NAME_MAX_LEN)
            : $full;
    }

    /**
     * Cari device GenieACS dari SN — coba bentuk apa adanya, lalu regex ekor
     * (pola sama pencarian Kambari/Dahlia: GenieACS simpan serial sebagai
     * bentuk vendor-ASCII huruf besar, mis. CMDCA200BB76).
     *
     * @return array<string, mixed>|null
     */
    private function findGenieAcsDevice(string $sn): ?array
    {
        foreach ([$sn, strtoupper($sn)] as $candidate) {
            $devices = $this->genieacs->queryDevices(['_deviceId._SerialNumber' => $candidate]);
            if ($devices !== []) {
                return $devices[0];
            }
        }

        $tail = substr($sn, -8);
        if (strlen($tail) >= 6) {
            $devices = $this->genieacs->queryDevices(['_deviceId._SerialNumber' => ['$regex' => '(?i)'.$tail]]);
            if ($devices !== []) {
                return $devices[0];
            }
        }

        return null;
    }

    /**
     * ConnectionStatus WAN PPPoE internet (Name mengandung "INTERNET", mis.
     * "3_INTERNET_R_VID_10"). null kalau WAN internet belum ada di tree.
     *
     * @param  array<string, mixed>  $device
     */
    private function internetWanStatus(array $device): ?string
    {
        $flat = [];
        $this->flatten($device, '', $flat);

        foreach ($flat as $path => $value) {
            if (preg_match('/WANPPPConnection\.\d+\.Name$/i', $path)
                && is_string($value)
                && stripos($value, 'INTERNET') !== false) {
                $statusPath = preg_replace('/\.Name$/i', '.ConnectionStatus', $path);
                $status = $flat[$statusPath] ?? null;

                return is_string($status) ? $status : null;
            }
        }

        return null;
    }

    /**
     * Ratakan struktur device GenieACS (node param = {_value,...}) jadi
     * path -> value. Key berawalan `_` (metadata) dilewati.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $out
     */
    private function flatten(array $node, string $prefix, array &$out): void
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && str_starts_with($key, '_')) {
                continue;
            }
            if (is_array($value) && array_key_exists('_value', $value)) {
                $out[$prefix.$key] = $value['_value'];
            } elseif (is_array($value)) {
                $this->flatten($value, $prefix.$key.'.', $out);
            }
        }
    }
}
