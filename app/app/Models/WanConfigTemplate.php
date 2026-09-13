<?php

namespace App\Models;

use App\Enums\MikrotikSyncStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.12.4 — satu baris = satu kombinasi `ppp_package_id` × `modem_type_id`
 * (matrix Template Konfig CPE). `modem_type_id` NULL = template
 * default/fallback untuk paket itu, berlaku apa pun modemnya — lihat
 * migration `create_wan_config_templates_table` untuk constraint
 * uniqueness-nya (dua lapis, termasuk kasus NULL).
 *
 * Menggantikan `RemoteWanConfig` (singleton fleet-wide) — DIBANGUN
 * PARALEL, bukan pengganti langsung. `RemoteWanConfig` belum
 * dihapus/dimatikan.
 *
 * TIDAK ADA kolom serial-allowlist di sini (beda dari `RemoteWanConfig`)
 * — SN yang di-scope preset GenieACS per-template dihitung DINAMIS saat
 * sync, cross-reference `customers.ppp_package_id` +
 * `work_order_devices.modem_type_id`. Query cross-reference-nya sendiri:
 * v0.12.5 (GenieAcsPresetService baru per-template), belum ada di sini.
 *
 * `markSynced()`/`markSyncFailed()`/`markSyncPending()` — pola identik
 * `RemoteWanConfig`, dipakai job sync (v0.12.5) nanti.
 */
class WanConfigTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'ppp_package_id',
        'modem_type_id',
        'enabled',
        'wan1_enabled',
        'wan1_vlan',
        'wan1_pppoe_username',
        'wan1_pppoe_password',
        'wan2_enabled',
        'wan2_vlan',
        'genieacs_sync_status',
        'genieacs_synced_at',
        'genieacs_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'wan1_enabled' => 'boolean',
            'wan1_vlan' => 'integer',
            'wan2_enabled' => 'boolean',
            'wan2_vlan' => 'integer',
            'genieacs_sync_status' => MikrotikSyncStatus::class,
            'genieacs_synced_at' => 'datetime',
        ];
    }

    public function pppPackage(): BelongsTo
    {
        return $this->belongsTo(PppPackage::class);
    }

    public function modemType(): BelongsTo
    {
        return $this->belongsTo(ModemType::class);
    }

    /**
     * True kalau baris ini template default/fallback paket-nya (berlaku
     * apa pun modemnya) — bukan template ber-Tipe-Modem spesifik.
     */
    public function isDefaultForPackage(): bool
    {
        return $this->modem_type_id === null;
    }

    public function markSynced(): void
    {
        $this->forceFill([
            'genieacs_sync_status' => MikrotikSyncStatus::Synced,
            'genieacs_synced_at' => now(),
            'genieacs_sync_error' => null,
        ])->save();
    }

    public function markSyncFailed(string $message): void
    {
        $this->forceFill([
            'genieacs_sync_status' => MikrotikSyncStatus::Failed,
            'genieacs_sync_error' => $message,
        ])->save();
    }

    public function markSyncPending(): void
    {
        $this->forceFill([
            'genieacs_sync_status' => MikrotikSyncStatus::Pending,
            'genieacs_sync_error' => null,
        ])->save();
    }
}
