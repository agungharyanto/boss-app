<?php

namespace App\Models;

use App\Enums\ResellerStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ResellerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Reseller extends Model
{
    /** @use HasFactory<ResellerFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'email',
        'phone',
        'address',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ResellerStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $reseller) {
            $reseller->slug ??= Str::slug($reseller->name);
        });
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function packagePricing(): HasMany
    {
        return $this->hasMany(ResellerPackagePricing::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'reseller_users')
            ->using(ResellerUser::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }
}
