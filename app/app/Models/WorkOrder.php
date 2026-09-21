<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WorkOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    /** @use HasFactory<WorkOrderFactory> */
    use BelongsToResellerScope, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'subscription_id',
        'customer_id',
        'technician_id',
        'odp_id',
        'odp_port_id',
        'status',
        'equipment_ready',
        'scheduled_at',
        'completed_at',
        'technician_confirmed_at',
        'dispatched_at',
        'last_reminder_sent_at',
        // v0.13.4.1 amendment — murni metadata (kapan/siapa klaim via
        // signed-link), TIDAK menyentuh WorkOrderStatus. Lihat migration's
        // own docblock.
        'claimed_at',
        'claimed_by_technician_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'equipment_ready' => 'boolean',
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'technician_confirmed_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'last_reminder_sent_at' => 'date',
            'claimed_at' => 'datetime',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * v0.13.4.1 amendment — teknisi UTAMA yang klaim WO ini via signed-link
     * (`claimed_by_technician_id`). GENUINELY terpisah dari technician()
     * (assignment resmi admin) dan claimPartners() (partner kerja).
     */
    public function claimedByTechnician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'claimed_by_technician_id');
    }

    public function odp(): BelongsTo
    {
        return $this->belongsTo(Odp::class);
    }

    public function odpPort(): BelongsTo
    {
        return $this->belongsTo(OdpPort::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(WorkOrderDevice::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(WorkOrderPhoto::class);
    }

    /**
     * v0.12.3 — Technician yang PERNAH klaim WO ini lewat API
     * (`work_order_technicians`). GENUINELY terpisah dari `technician()`
     * (assignment resmi admin via `technician_id`) — lihat migration's own
     * docblock.
     */
    public function claimedByTechnicians(): BelongsToMany
    {
        return $this->belongsToMany(Technician::class, 'work_order_technicians')
            ->withPivot('claimed_at')
            ->withTimestamps();
    }

    /**
     * v0.13.4.1 — partner kerja dipilih teknisi utama saat klaim via
     * signed-link. GENUINELY terpisah dari claimedByTechnicians() di atas
     * — lihat WorkOrderClaimPartner's own docblock.
     */
    public function claimPartners(): BelongsToMany
    {
        return $this->belongsToMany(Technician::class, 'work_order_claim_partners')
            ->withTimestamps();
    }

    public function toolUsages(): HasMany
    {
        return $this->hasMany(WorkOrderToolUsage::class);
    }

    public function modemUnits(): HasMany
    {
        return $this->hasMany(WorkOrderModemUnit::class);
    }
}
