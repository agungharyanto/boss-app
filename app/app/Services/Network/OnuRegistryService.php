<?php

namespace App\Services\Network;

use App\Enums\OnuRegistryStatus;
use App\Enums\OnuSyncStatus;
use App\Models\OltDevice;
use App\Models\OnuRegistry;
use App\Models\WorkOrderModemUnit;
use RuntimeException;

/**
 * Registry ONU, ON-DEMAND SAJA (v0.23.4) — tidak ada job berkala. Setiap
 * baris `onu_registries` adalah cache hasil lookup TERAKHIR, bukan
 * salinan lengkap OLT, dan TIDAK PERNAH jadi sumber kebenaran untuk
 * operasi tulis (sama aturan keras yang sudah dikunci di desain sidecar
 * v0.23.3 §7). Lihat docs/omci/onu-registry-design.md untuk desain
 * lengkap.
 *
 * BATAS KERAS: hanya operasi BACA (lewat OltSidecarClient) yang pernah
 * dipanggil di sini — tidak ada satu baris kode pun yang mengirim
 * operasi tulis ke OLT.
 */
class OnuRegistryService
{
    /**
     * Vendor yang operasi per-ONU-nya SUDAH TERUJI eksekusi nyata
     * (docs/omci/onu-registry-design.md §2, Opsi A yang disetujui Agung
     * 2026-09-22). HSGQ E04ID/G02ID SENGAJA TIDAK ada di sini — guard
     * sementara, dibuka setelah sesi riset tambahan memverifikasi operasi
     * per-ONU-nya (kemungkinan ditempelkan ke v0.23.6/v0.23.7).
     *
     * @var array<string, string> vendor key -> nama operation sidecar
     */
    private const SUPPORTED_VENDOR_OPERATIONS = [
        'zte_c300' => 'onu_detail_info',
    ];

    public function __construct(private readonly OltSidecarClient $sidecarClient) {}

    /**
     * @throws RuntimeException Kalau vendor OLT belum didukung sidecar sama sekali (OltDevice::
     *                          sidecarVendorKey()), operasi per-ONU vendor ini belum TERUJI
     *                          (HSGQ, lihat SUPPORTED_VENDOR_OPERATIONS), atau sidecar
     *                          melaporkan kegagalan baca.
     */
    public function syncOnu(OltDevice $oltDevice, string $vendorIdentifier): OnuRegistry
    {
        $vendorKey = $oltDevice->sidecarVendorKey();

        $operation = self::SUPPORTED_VENDOR_OPERATIONS[$vendorKey] ?? null;
        if ($operation === null) {
            throw new RuntimeException(
                'Operasi baca per-ONU untuk vendor ini belum diverifikasi terhadap OLT nyata, '.
                'perlu sesi riset tambahan sebelum diaktifkan (lihat docs/omci/onu-registry-design.md §2).'
            );
        }

        $existing = OnuRegistry::withoutGlobalScopes()
            ->where('olt_device_id', $oltDevice->id)
            ->where('vendor_identifier', $vendorIdentifier)
            ->first();

        $response = $this->sidecarClient->read(
            $oltDevice,
            $operation,
            ['onu' => $vendorIdentifier],
            requestedBy: null,
            maskSensitive: false,
        );

        if (($response['success'] ?? false) !== true) {
            // Baris LAMA (kalau ada) TIDAK dihapus/diubah field datanya —
            // hanya ditandai `stale` (§1/desain — cache yang gagal
            // di-refresh tetap lebih berguna daripada dihapus). Baris
            // yang BELUM PERNAH ada tetap tidak dibuat sama sekali.
            if ($existing !== null) {
                $existing->update(['sync_status' => OnuSyncStatus::Stale]);
            }

            $message = $response['device_message'] ?? $response['error'] ?? 'Sidecar melaporkan kegagalan tanpa pesan.';
            throw new RuntimeException("Sync ONU gagal: {$message}");
        }

        $fields = $response['data'] ?? [];
        if (! is_array($fields)) {
            $fields = [];
        }

        $attributes = match ($vendorKey) {
            'zte_c300' => $this->mapZteFields($fields),
            default => [],
        };

        /** @var OnuRegistry $registry */
        $registry = OnuRegistry::withoutGlobalScopes()->updateOrCreate(
            ['olt_device_id' => $oltDevice->id, 'vendor_identifier' => $vendorIdentifier],
            [
                'tenant_id' => $oltDevice->tenant_id,
                'sync_status' => OnuSyncStatus::Synced,
                'last_synced_at' => now(),
                'raw_payload' => $fields,
                ...$attributes,
            ],
        );

        if ($registry->serial_number !== null || $registry->mac_address !== null) {
            $match = $this->findWorkOrderMatch($registry->serial_number, $registry->mac_address);
            if ($match !== null) {
                $registry->update(['work_order_modem_unit_id' => $match->id]);
            }
        }

        return $registry;
    }

    /**
     * `work_order_modem_units` SENGAJA tidak punya unique constraint di
     * serial_number/mac_address (lihat migration-nya sendiri) — bisa ada
     * lebih dari satu match. Ambil yang PALING BARU dicatat sebagai
     * kandidat paling mungkin relevan — BUKAN jaminan match yang benar,
     * murni heuristik terbaik yang tersedia.
     *
     * Tidak ada global scope tenant di WorkOrderModemUnit (dikonfirmasi
     * langsung dari model-nya — scoped implisit lewat work_order_id,
     * bukan lewat BelongsToTenant), jadi query Eloquent biasa tanpa
     * withoutGlobalScopes() (tidak ada yang perlu di-bypass). BOSS-009
     * TIDAK dilanggar — ini query lintas TABEL dalam database boss_db
     * yang sama, bukan lintas DATABASE (dikonfirmasi eksplisit Agung
     * 2026-09-22).
     */
    public function findWorkOrderMatch(?string $serialNumber, ?string $macAddress): ?WorkOrderModemUnit
    {
        if ($serialNumber === null && $macAddress === null) {
            return null;
        }

        return WorkOrderModemUnit::query()
            ->when($serialNumber !== null, fn ($query) => $query->where('serial_number', $serialNumber))
            ->when($macAddress !== null, fn ($query) => $query->orWhere('mac_address', $macAddress))
            ->latest()
            ->first();
    }

    /**
     * @param  array<string, string>  $fields  Hasil parse_field_value_block() sidecar (mis. {"Name": "...",
     *                                         "Type": "...", "State": "...", "Phase state": "...", ...}).
     * @return array<string, mixed>
     */
    private function mapZteFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $key => $value) {
            $normalized[strtolower(trim($key))] = trim((string) $value);
        }

        return [
            // Catatan jujur (lihat docs/omci/onu-registry-design.md §6):
            // field "Sn" TIDAK terlihat eksplisit di ringkasan
            // detail-info yang sudah didokumentasikan — kandidat nama
            // field dicoba beberapa kemungkinan, tapi HASIL NYATA baru
            // dikonfirmasi saat verifikasi (§9). Kalau tidak ditemukan,
            // serial_number tetap null — bukan fatal, registry tetap
            // berguna untuk status/nama, hanya matching WO tidak
            // berjalan untuk baris itu.
            'serial_number' => $normalized['sn'] ?? $normalized['serial number'] ?? null,
            'mac_address' => $normalized['mac'] ?? $normalized['mac address'] ?? null,
            'status' => $this->mapZteStatus($normalized),
            'name' => $normalized['name'] ?? null,
            'description' => $this->nullIfNoneLike($normalized['description'] ?? null),
        ];
    }

    private function mapZteStatus(array $normalizedFields): OnuRegistryStatus
    {
        $phaseState = strtolower($normalizedFields['phase state'] ?? '');
        if ($phaseState === 'working') {
            return OnuRegistryStatus::Active;
        }
        if ($phaseState === 'offline') {
            return OnuRegistryStatus::Offline;
        }

        $state = strtolower($normalizedFields['state'] ?? '');
        if ($state === 'ready') {
            return OnuRegistryStatus::Active;
        }
        if ($state === 'unknown') {
            return OnuRegistryStatus::Unconfigured;
        }

        return OnuRegistryStatus::Unknown;
    }

    /**
     * Device ZTE menampilkan "none" (string literal) untuk field
     * description kosong, bukan string kosong — dinormalisasi jadi null
     * di sini supaya konsisten dengan konvensi nullable kolom.
     */
    private function nullIfNoneLike(?string $value): ?string
    {
        if ($value === null || $value === '' || strtolower($value) === 'none') {
            return null;
        }

        return $value;
    }
}
