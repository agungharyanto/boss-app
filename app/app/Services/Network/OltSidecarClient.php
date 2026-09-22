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
 */
class OltSidecarClient
{
    /**
     * Mapping (manufacturer name, model name) -> vendor key yang dikenal
     * sidecar. Dijaga eksplisit (bukan derivasi otomatis dari string) agar
     * sebuah OLT yang belum genuinely didukung sidecar GAGAL JELAS
     * ("OLT ini belum didukung sidecar"), bukan diam-diam mengirim vendor
     * key yang salah.
     */
    private const VENDOR_MAP = [
        'HSGQ|HSGQ-E04ID' => 'hsgq_e04id',
        'HSGQ|HSGQ-G02ID' => 'hsgq_g02id',
        'ZTE|C300' => 'zte_c300',
    ];

    public function __construct(private readonly OltSidecarHmac $hmac) {}

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed> Body respons terstruktur dari sidecar (lihat docs/omci/sidecar-design.md §4).
     *
     * @throws RuntimeException Kalau OLT tidak dikenal sidecar, atau request ke sidecar gagal di level HTTP.
     */
    public function read(OltDevice $oltDevice, string $operation, array $args = [], ?int $requestedBy = null): array
    {
        $vendor = $this->resolveVendorKey($oltDevice);
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

    private function resolveVendorKey(OltDevice $oltDevice): string
    {
        $manufacturer = strtoupper(trim($oltDevice->oltModel?->manufacturer?->name ?? ''));
        $model = strtoupper(trim($oltDevice->oltModel?->name ?? ''));
        $key = "{$manufacturer}|{$model}";

        return self::VENDOR_MAP[$key]
            ?? throw new RuntimeException("OltDevice #{$oltDevice->id} ({$manufacturer} {$model}) belum didukung sidecar OLT.");
    }
}
