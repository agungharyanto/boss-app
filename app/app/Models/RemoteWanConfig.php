<?php

namespace App\Models;

use App\Enums\MikrotikSyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Singleton (id=1) — konfigurasi GenieACS Auto-WAN. Lihat migration
 * `2026_09_07_150000_create_remote_wan_configs_table` untuk alasan
 * platform-level (bukan per-tenant).
 *
 * Nilai di sini di-serialize jadi `args` provision `default-wan` GenieACS
 * lewat `App\Services\Network\GenieAcsPresetService` — urutan args:
 *   [enabled, wan1_enabled, wan1_vlan, wan1_pppoe_username,
 *    wan1_pppoe_password, wan2_enabled, wan2_vlan]
 * (lihat `docker/genieacs/presets/default-wan.js` — kontrak posisional).
 */
class RemoteWanConfig extends Model
{
    public const SINGLETON_ID = 1;

    protected $fillable = [
        'enabled',
        'wan1_enabled',
        'wan1_vlan',
        'wan1_pppoe_username',
        'wan1_pppoe_password',
        'wan2_enabled',
        'wan2_vlan',
        'updated_by',
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

    public static function current(): self
    {
        $config = static::query()->firstOrCreate(['id' => self::SINGLETON_ID]);

        // firstOrCreate() dengan hanya ['id' => 1] tidak mengisi nilai
        // default kolom DB ke instance in-memory — refresh sekali kalau
        // baris baru dibuat supaya wan1_vlan/username/dst terisi.
        return $config->wasRecentlyCreated ? $config->refresh() : $config;
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Kontrak posisional `args` provision `default-wan` GenieACS.
     * GenieACS meng-evaluasi tiap arg sebagai ekspresi — boolean/angka
     * dikirim sebagai literal JS (`true`/`false`/`1000`), string sebagai
     * string. `default-wan.js` membaca `args[0]`..`args[6]`.
     *
     * @return array<int, bool|int|string>
     */
    public function toProvisionArgs(): array
    {
        return [
            $this->enabled,
            $this->wan1_enabled,
            $this->wan1_vlan,
            (string) $this->wan1_pppoe_username,
            (string) $this->wan1_pppoe_password,
            $this->wan2_enabled,
            $this->wan2_vlan,
        ];
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
