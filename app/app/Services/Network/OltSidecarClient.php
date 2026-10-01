<?php

namespace App\Services\Network;

use App\Models\OltDevice;
use App\Support\OltSidecarHmac;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client Laravel -> sidecar OLT (v0.23.3). Pola HTTP POST identik
 * App\Jobs\SendWhatsappMessageJob::sendToGateway() — json_encode body dulu,
 * sign string PERSIS itu, Http::withBody() (BUKAN Http::post($url, $data)
 * yang re-encode sendiri, karena signature dihitung atas string mentah).
 *
 * boss-app men-decrypt kredensial OLT SENDIRI (dari olt_devices, seperti
 * biasa) lalu menyertakannya LANGSUNG di body request — TIDAK ADA
 * panggilan balik sidecar->boss-app untuk meminta kredensial (revisi
 * keputusan Agung 2026-09-22, lihat docs/omci/sidecar-design.md §8).
 * Kredensial melintas satu kali, di jalur yang sudah dilindungi HMAC +
 * TLS internal (boss-network).
 *
 * Kredensial HANYA PERNAH diambil lewat App\Models\OltDevice::
 * sidecarConnectionPayload() — satu-satunya titik resmi (dipakai di sini
 * DAN di test). Insiden nyata (2026-09-22, lihat docs/omci/
 * backlog-security.md poin 5): password sempat tercetak di sesi debugging
 * karena field diakses langsung, di luar jalur ini — JANGAN ulangi.
 *
 * BATAS KERAS v0.23.3: hanya operasi BACA yang boleh dikirim lewat
 * client ini — sidecar sendiri hanya mengimplementasikan operasi TERUJI
 * (lihat masing-masing modul vendor Python), tapi tidak ada satu pun
 * pemanggil produksi client ini yang boleh mengirim operasi tulis sampai
 * ada keputusan eksplisit baru (v0.23.5+).
 *
 * v0.23.5 — `activateOnu()`/`saveConfig()` menambah TULIS, dengan desain
 * terkunci Agung (2026-09-22): method ini TIDAK PERNAH membangun/mengirim
 * string command CLI — hanya `$params` (array terstruktur: onu_id, sn,
 * onu_type, name, tcont_profile, traffic_profile, vlan_pppoe/mgmt/bridge,
 * pon_interface). Sidecar sendiri yang merakit + memvalidasi urutan
 * command dari template internal (`zte_c300.OPERATIONS_WRITE`) — Laravel
 * tidak pernah tahu/mengirim bentuk command CLI-nya sama sekali.
 * `activateOnu()` menjalankan konfigurasi sampai `end` TANPA `wr`
 * (verifikasi online dilakukan pemanggil DI ANTARA `activateOnu()` dan
 * `saveConfig()`, bukan di dalam client ini); `saveConfig()` adalah
 * panggilan TERPISAH yang HANYA mengirim `wr`.
 */
class OltSidecarClient
{
    public function __construct(private readonly OltSidecarHmac $hmac) {}

    /**
     * @param  array<string, mixed>  $args
     * @param  bool  $maskSensitive  Default true (perilaku v0.23.3, tidak berubah untuk pemanggil mana
     *                               pun yang sudah ada) — sidecar mem-mask SN/MAC/nama sebelum respons
     *                               dikirim. HANYA App\Services\Network\OnuRegistryService (v0.23.4)
     *                               yang mengirim false, karena konsumennya kode PHP yang memang perlu
     *                               menyimpan SN/MAC lengkap untuk matching work_order_modem_units —
     *                               lihat docs/omci/sidecar-design.md §4 dan
     *                               docs/omci/onu-registry-design.md §3 untuk alasan lengkap.
     * @return array<string, mixed> Body respons terstruktur dari sidecar (lihat docs/omci/sidecar-design.md §4).
     *
     * @throws RuntimeException Kalau OLT tidak dikenal sidecar, atau request ke sidecar gagal di level HTTP.
     */
    public function read(
        OltDevice $oltDevice,
        string $operation,
        array $args = [],
        ?int $requestedBy = null,
        bool $maskSensitive = true,
    ): array {
        $vendor = $oltDevice->sidecarVendorKey();
        // Kredensial HANYA pernah diambil lewat method terpusat ini —
        // JANGAN akses ssh_password/telnet_password langsung di sini atau
        // di tempat lain mana pun (lihat docblock OltDevice::
        // sidecarConnectionPayload() untuk alasan/insiden yang melatarinya).
        $connection = $oltDevice->sidecarConnectionPayload();

        $body = json_encode([
            'olt_device_id' => $oltDevice->id,
            'vendor' => $vendor,
            'operation' => $operation,
            'connection' => $connection,
            'args' => $args,
            'requested_by' => $requestedBy,
            'mask_sensitive' => $maskSensitive,
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = $this->hmac->sign($body, $timestamp);

        $baseUrl = rtrim((string) config('services.olt_sidecar.url'), '/');

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'X-Olt-Timestamp' => (string) $timestamp,
                    'X-Olt-Signature' => $signature,
                ])
                // Sesi CLI OLT (login + command baca + logout) bisa
                // sampai puluhan detik pada koneksi lambat — beri margin
                // di atas timeout lock antrean sidecar sendiri (30s,
                // lihat app/olt_queue.py) + durasi sesi CLI itu sendiri.
                ->timeout(75)
                ->post("{$baseUrl}/olt/{$oltDevice->id}/read");
        } finally {
            // Defense-in-depth — buang referensi array kredensial dari
            // scope method ini secepat mungkin setelah request selesai/gagal.
            unset($connection);
        }

        return $response->json() ?? [
            'success' => false,
            'error' => "Respons sidecar tidak valid (HTTP {$response->status()})",
        ];
    }

    /**
     * v0.23.5 — jalankan konfigurasi ONU baru sampai `end`, TANPA `wr`.
     * `$params` array TERSTRUKTUR saja (onu_id, sn, onu_type, name,
     * tcont_profile, traffic_profile, vlan_pppoe/vlan_mgmt/vlan_bridge,
     * pon_interface) — TIDAK PERNAH string command CLI, sidecar sendiri
     * yang merakit + memvalidasi urutan command dari template internal.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function activateOnu(OltDevice $oltDevice, array $params, ?int $requestedBy = null): array
    {
        return $this->apply($oltDevice, 'activate_onu', $params, $requestedBy);
    }

    /**
     * v0.23.5 — koreksi VLAN PPPoE pada ONU yang SUDAH ter-apply, TANPA
     * reapply seluruh blok dari nol (sidecar
     * `zte_c300.OPERATIONS_WRITE['fix_onu_vlan']` hanya menyentuh 3 baris
     * yang menyebut VLAN PPPoE — tidak pernah mengulang registrasi PON
     * atau menyentuh VLAN mgmt/bridge). `$params`:
     * `pon_interface`/`onu_id`/`new_vlan_pppoe`.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function fixOnuVlan(OltDevice $oltDevice, array $params, ?int $requestedBy = null): array
    {
        return $this->apply($oltDevice, 'fix_onu_vlan', $params, $requestedBy);
    }

    /**
     * v0.23.5 — hapus registrasi ONU di level PON (`no onu <id>`, sintaks
     * ditemukan lewat bantuan CLI setelah fix_onu_vlan terbukti
     * menghasilkan config campur aduk). `$params`: `pon_interface`/
     * `onu_id`.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function deleteOnu(OltDevice $oltDevice, array $params, ?int $requestedBy = null): array
    {
        return $this->apply($oltDevice, 'delete_onu', $params, $requestedBy);
    }

    /**
     * v0.23.5 (Bagian B) — tambah baris `pppoe <host_id>` di
     * `pon-onu-mng gpon-onu_{pon}:{onu_id}`. `$params`: `pon_interface`/
     * `onu_id`/`host_id`/`username`/`password`/`nat_enabled` (opsional,
     * default true). HANYA untuk host_id yang genuinely masih kosong —
     * lihat docblock `_build_add_pppoe_commands()` (sidecar) untuk alasan.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function addPppoe(OltDevice $oltDevice, array $params, ?int $requestedBy = null): array
    {
        return $this->apply($oltDevice, 'add_pppoe', $params, $requestedBy);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function apply(OltDevice $oltDevice, string $operation, array $params, ?int $requestedBy): array
    {
        $vendor = $oltDevice->sidecarVendorKey();
        $connection = $oltDevice->sidecarConnectionPayload();

        $body = json_encode([
            'olt_device_id' => $oltDevice->id,
            'vendor' => $vendor,
            'operation' => $operation,
            'connection' => $connection,
            'params' => $params,
            'requested_by' => $requestedBy,
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = $this->hmac->sign($body, $timestamp);
        $baseUrl = rtrim((string) config('services.olt_sidecar.url'), '/');

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'X-Olt-Timestamp' => (string) $timestamp,
                    'X-Olt-Signature' => $signature,
                ])
                ->timeout(75)
                ->post("{$baseUrl}/olt/{$oltDevice->id}/apply");
        } finally {
            unset($connection);
        }

        return $response->json() ?? [
            'success' => false,
            'error' => "Respons sidecar tidak valid (HTTP {$response->status()})",
        ];
    }

    /**
     * v0.23.5 — kirim HANYA `wr` (save config) di sesi terpisah dari
     * activateOnu(). Dipanggil oleh pemanggil (bukan sidecar) HANYA
     * setelah verifikasi online sukses — client ini tidak memaksakan
     * urutan itu sendiri, murni transport.
     *
     * @return array<string, mixed>
     */
    public function saveConfig(OltDevice $oltDevice, ?int $requestedBy = null): array
    {
        $vendor = $oltDevice->sidecarVendorKey();
        $connection = $oltDevice->sidecarConnectionPayload();

        $body = json_encode([
            'olt_device_id' => $oltDevice->id,
            'vendor' => $vendor,
            'connection' => $connection,
            'requested_by' => $requestedBy,
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = $this->hmac->sign($body, $timestamp);
        $baseUrl = rtrim((string) config('services.olt_sidecar.url'), '/');

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'X-Olt-Timestamp' => (string) $timestamp,
                    'X-Olt-Signature' => $signature,
                ])
                ->timeout(45)
                ->post("{$baseUrl}/olt/{$oltDevice->id}/save");
        } finally {
            unset($connection);
        }

        return $response->json() ?? [
            'success' => false,
            'error' => "Respons sidecar tidak valid (HTTP {$response->status()})",
        ];
    }

    /**
     * v0.23.5 — HANYA untuk mencari command CLI yang benar lewat bantuan
     * `?` (dipakai setelah `wr` ditolak device sebagai command save yang
     * tidak dikenal). `$query` HARUS diakhiri `?` (divalidasi ketat lagi
     * di sisi sidecar, _HELP_QUERY_RE) — TIDAK PERNAH bisa dipakai untuk
     * mengeksekusi command config sungguhan.
     *
     * @return array<string, mixed>
     */
    public function probeConfigHelp(OltDevice $oltDevice, string $query, ?int $requestedBy = null): array
    {
        $vendor = $oltDevice->sidecarVendorKey();
        $connection = $oltDevice->sidecarConnectionPayload();

        $body = json_encode([
            'olt_device_id' => $oltDevice->id,
            'vendor' => $vendor,
            'connection' => $connection,
            'params' => ['query' => $query],
            'requested_by' => $requestedBy,
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = $this->hmac->sign($body, $timestamp);
        $baseUrl = rtrim((string) config('services.olt_sidecar.url'), '/');

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'X-Olt-Timestamp' => (string) $timestamp,
                    'X-Olt-Signature' => $signature,
                ])
                ->timeout(45)
                ->post("{$baseUrl}/olt/{$oltDevice->id}/probe");
        } finally {
            unset($connection);
        }

        return $response->json() ?? [
            'success' => false,
            'error' => "Respons sidecar tidak valid (HTTP {$response->status()})",
        ];
    }

    /**
     * v0.23.5 — sama seperti probeConfigHelp(), TAPI masuk satu langkah
     * navigasi lebih dalam (`interface gpon-olt_{$ponInterface}`) sebelum
     * query bantuan — dipakai untuk mencari sintaks command yang hanya
     * ada di context interface PON (mis. `no onu <id>`). `$query` HARUS
     * diakhiri `?` (divalidasi ketat lagi di sisi sidecar).
     *
     * @return array<string, mixed>
     */
    public function probeOnuInterfaceHelp(OltDevice $oltDevice, string $ponInterface, string $query, ?int $requestedBy = null): array
    {
        $vendor = $oltDevice->sidecarVendorKey();
        $connection = $oltDevice->sidecarConnectionPayload();

        $body = json_encode([
            'olt_device_id' => $oltDevice->id,
            'vendor' => $vendor,
            'operation' => 'onu_interface_help',
            'connection' => $connection,
            'params' => ['pon_interface' => $ponInterface, 'query' => $query],
            'requested_by' => $requestedBy,
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = $this->hmac->sign($body, $timestamp);
        $baseUrl = rtrim((string) config('services.olt_sidecar.url'), '/');

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'X-Olt-Timestamp' => (string) $timestamp,
                    'X-Olt-Signature' => $signature,
                ])
                ->timeout(45)
                ->post("{$baseUrl}/olt/{$oltDevice->id}/probe");
        } finally {
            unset($connection);
        }

        return $response->json() ?? [
            'success' => false,
            'error' => "Respons sidecar tidak valid (HTTP {$response->status()})",
        ];
    }

    /**
     * v0.23.5 — sama seperti probeOnuInterfaceHelp(), TAPI masuk ke
     * `interface gpon-onu_{$ponInterface}:{$onuId}` (level SERVICE
     * per-ONU), bukan `interface gpon-olt_{$ponInterface}` (level
     * registrasi PON). Dipakai investigasi parameter "WAN VLAN"
     * tersembunyi yang mungkin ada di level ini.
     *
     * @return array<string, mixed>
     */
    public function probeOnuServiceHelp(OltDevice $oltDevice, string $ponInterface, int $onuId, string $query, ?int $requestedBy = null): array
    {
        $vendor = $oltDevice->sidecarVendorKey();
        $connection = $oltDevice->sidecarConnectionPayload();

        $body = json_encode([
            'olt_device_id' => $oltDevice->id,
            'vendor' => $vendor,
            'operation' => 'onu_service_help',
            'connection' => $connection,
            'params' => ['pon_interface' => $ponInterface, 'onu_id' => $onuId, 'query' => $query],
            'requested_by' => $requestedBy,
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = $this->hmac->sign($body, $timestamp);
        $baseUrl = rtrim((string) config('services.olt_sidecar.url'), '/');

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'X-Olt-Timestamp' => (string) $timestamp,
                    'X-Olt-Signature' => $signature,
                ])
                ->timeout(45)
                ->post("{$baseUrl}/olt/{$oltDevice->id}/probe");
        } finally {
            unset($connection);
        }

        return $response->json() ?? [
            'success' => false,
            'error' => "Respons sidecar tidak valid (HTTP {$response->status()})",
        ];
    }

    /**
     * v0.23.5 — sama seperti probeOnuServiceHelp(), TAPI masuk ke
     * `pon-onu-mng gpon-onu_{$ponInterface}:{$onuId}` (node TERPISAH dari
     * `interface gpon-onu_...`). Dipakai investigasi sintaks
     * `pppoe <n> nat enable user ... password ...`.
     *
     * @return array<string, mixed>
     */
    public function probeOnuMngHelp(OltDevice $oltDevice, string $ponInterface, int $onuId, string $query, ?int $requestedBy = null): array
    {
        $vendor = $oltDevice->sidecarVendorKey();
        $connection = $oltDevice->sidecarConnectionPayload();

        $body = json_encode([
            'olt_device_id' => $oltDevice->id,
            'vendor' => $vendor,
            'operation' => 'onu_mng_help',
            'connection' => $connection,
            'params' => ['pon_interface' => $ponInterface, 'onu_id' => $onuId, 'query' => $query],
            'requested_by' => $requestedBy,
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = $this->hmac->sign($body, $timestamp);
        $baseUrl = rtrim((string) config('services.olt_sidecar.url'), '/');

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'X-Olt-Timestamp' => (string) $timestamp,
                    'X-Olt-Signature' => $signature,
                ])
                ->timeout(45)
                ->post("{$baseUrl}/olt/{$oltDevice->id}/probe");
        } finally {
            unset($connection);
        }

        return $response->json() ?? [
            'success' => false,
            'error' => "Respons sidecar tidak valid (HTTP {$response->status()})",
        ];
    }
}
