<?php

namespace App\Models;

use App\Enums\MikrotikSyncStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WanConfigTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.12.5 (revisi arsitektur, dikonfirmasi Agung) — Template Konfig CPE
 * TIDAK terikat Paket sama sekali, dibedakan HANYA oleh Tipe Modem.
 * `modem_type_id` NULL = template generic/fallback (tanpa Tipe Modem
 * spesifik). TIDAK ADA unique constraint pada `modem_type_id` — beberapa
 * Template boleh punya Tipe Modem yang sama, dibedakan lewat `name`
 * (bebas, tanpa constraint ketat).
 *
 * Assignment ke device (`cpe_devices.wan_config_template_id`) terjadi
 * lewat auto-suggest (best-effort, lihat
 * App\Services\Network\WanConfigTemplateSuggestionService) atau override
 * manual admin di Detail Perangkat CPE — BUKAN lewat kombinasi
 * Paket+Modem seperti desain v0.12.4 asli (sudah di-rework total).
 *
 * Menggantikan `RemoteWanConfig` (singleton fleet-wide) — DIBANGUN
 * PARALEL, bukan pengganti langsung. `RemoteWanConfig` belum
 * dihapus/dimatikan, `/remote-config` tidak disentuh sama sekali.
 *
 * TIDAK ADA kolom serial-allowlist di sini — sama seperti desain asli,
 * push ke GenieACS (v0.12.6) akan cross-reference `cpe_devices.
 * wan_config_template_id` langsung, bukan allowlist manual.
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

    public function modemType(): BelongsTo
    {
        return $this->belongsTo(ModemType::class);
    }

    /**
     * True kalau baris ini template generic/fallback (tanpa Tipe Modem
     * spesifik) — bukan template ber-Tipe-Modem spesifik. Dulu bernama
     * isDefaultForPackage() (v0.12.4, sebelum Paket dihapus dari skema).
     */
    public function isGeneric(): bool
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
