<?php

namespace App\Models;

use App\Enums\MikrotikSyncStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WanConfigTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.12.5 (koreksi arsitektur, kembali ke matrix — dikonfirmasi Agung dari
 * klarifikasi chat planning) — satu baris = satu kombinasi
 * `ppp_package_id` x `modem_type_id`. `modem_type_id` NULL = template
 * default/fallback untuk paket itu, berlaku apa pun modemnya. Paket harus
 * sudah ada di Profil PPP dulu (FK `restrictOnDelete()`) sebelum
 * Template-nya bisa dibuat.
 *
 * `name` — bebas, TIDAK unique (dua template berbeda modem_type_id untuk
 * paket yang sama boleh punya nama mirip, tidak dibatasi).
 *
 * Assignment ke device (`cpe_devices.wan_config_template_id`) terjadi
 * lewat auto-suggest (App\Services\Network\WanConfigTemplateSuggestionService
 * — resolve dari customer.ppp_package_id + cpe_devices.manufacturer) atau
 * override manual admin di Detail Perangkat CPE.
 *
 * Menggantikan `RemoteWanConfig` (singleton fleet-wide) — DIBANGUN
 * PARALEL, bukan pengganti langsung. `RemoteWanConfig` belum
 * dihapus/dimatikan, `/remote-config` tidak disentuh sama sekali.
 *
 * TIDAK ADA kolom serial-allowlist di sini — push ke GenieACS (v0.12.6)
 * akan cross-reference `cpe_devices.wan_config_template_id` langsung.
 *
 * `markSynced()`/`markSyncFailed()`/`markSyncPending()` — pola identik
 * `RemoteWanConfig`, dipakai job sync (v0.12.6) nanti.
 */
class WanConfigTemplate extends Model
{
    /** @use HasFactory<WanConfigTemplateFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
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
