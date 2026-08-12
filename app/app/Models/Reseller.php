<?php

namespace App\Models;

use App\Enums\ResellerStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\NameToCodeDeriver;
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
        'code',
        'invoice_code',
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
            // Auto-derived fallback for invoice numbering (v0.3.4) — admin
            // can override via ResellerService::updateReseller(). Uppercase
            // alnum-only prefix from the slug, capped at 12 chars so
            // "INV/{code}/2026/08/000123" stays a reasonable length.
            $reseller->invoice_code ??= strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $reseller->slug), 0, 12));

            // `code` is a separate concept from invoice_code (CID's
            // building block, not invoice numbering) — never overrides an
            // explicitly-set code, silently leaves it null if name is
            // blank.
            if (blank($reseller->code) && filled($reseller->name)) {
                $reseller->code = NameToCodeDeriver::deriveUnique(
                    $reseller->name,
                    fn (string $candidate) => self::withoutGlobalScopes()
                        ->where('tenant_id', $reseller->tenant_id)
                        ->where('code', $candidate)
                        ->exists()
                );
            }
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

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'reseller_users')
            ->using(ResellerUser::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }
}
