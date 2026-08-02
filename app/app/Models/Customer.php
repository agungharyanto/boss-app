<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'address',
        'phone_number',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function authorizedContact(): HasOne
    {
        return $this->hasOne(CustomerContact::class)->where('is_authorized_contact', true);
    }

    public function timelineEntries(): HasMany
    {
        return $this->hasMany(CustomerTimelineEntry::class)->latest('created_at');
    }
}
