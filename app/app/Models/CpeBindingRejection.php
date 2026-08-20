<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CpeBindingRejectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remembers a (genieacs_device_id, customer_id) pair an admin explicitly
 * unbound as wrong — see App\Livewire\Network\CpeDeviceIndex::unbindDevice()
 * (the only writer) and App\Services\Network\LegacyDeviceMatcherService
 * (the only reader, checked before ever re-binding the same pair).
 */
class CpeBindingRejection extends Model
{
    /** @use HasFactory<CpeBindingRejectionFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'genieacs_device_id',
        'customer_id',
        'rejected_at',
        'rejected_by',
    ];

    protected function casts(): array
    {
        return [
            'rejected_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
