<?php

namespace App\Models;

use App\Enums\CpeDeviceStatus;
use App\Enums\ModemTypeAssignmentSource;
use App\Enums\Tr069Root;
use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CpeDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CpeDevice extends Model
{
    /** @use HasFactory<CpeDeviceFactory> */
    use BelongsToResellerScope, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'reseller_id',
        'work_order_device_id',
        'genieacs_device_id',
        'manufacturer',
        'model_name',
        'serial_number',
        'import_match_confidence',
        'tr069_root',
        'status',
        'status_changed_at',
        'last_inform_at',
        'bound_at',
        'wifi_provisioned_at',
        // v0.12.5 — hasil auto-suggest (ModemTypeSuggestionService) atau
        // override manual admin, lihat ModemTypeAssignmentSource. Template
        // Konfig CPE TIDAK disimpan di sini — di-resolve on-demand dari
        // kombinasi customer.ppp_package_id + modem_type_id (lihat
        // App\Services\Network\WanConfigTemplateResolverService).
        'modem_type_id',
        'modem_type_source',
    ];

    protected function casts(): array
    {
        return [
            'tr069_root' => Tr069Root::class,
            'status' => CpeDeviceStatus::class,
            'status_changed_at' => 'datetime',
            'last_inform_at' => 'datetime',
            'bound_at' => 'datetime',
            'wifi_provisioned_at' => 'datetime',
            'modem_type_source' => ModemTypeAssignmentSource::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function workOrderDevice(): BelongsTo
    {
        return $this->belongsTo(WorkOrderDevice::class);
    }

    /**
     * v0.12.5 — Tipe Modem ter-assign ke device ini (auto-suggest OUI
     * matching atau override manual, lihat modem_type_source).
     */
    public function modemType(): BelongsTo
    {
        return $this->belongsTo(ModemType::class);
    }

    /**
     * Remote action audit trail (v0.7.4) — see App\Models\CpeActionLog.
     */
    public function actionLogs(): HasMany
    {
        return $this->hasMany(CpeActionLog::class);
    }

    /**
     * TR-069 Hosts.Host history (v0.7.6) — see App\Models\CpeConnectedHost.
     */
    public function connectedHosts(): HasMany
    {
        return $this->hasMany(CpeConnectedHost::class);
    }

    /**
     * RX Power poll history (v0.8.3) — see App\Models\CpeSignalHistory /
     * App\Console\Commands\SyncCpeSignalHistory.
     */
    public function signalHistory(): HasMany
    {
        return $this->hasMany(CpeSignalHistory::class);
    }
}
