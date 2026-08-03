<?php

namespace App\Models;

use App\Enums\ResellerPackagePricingStatus;
use App\Models\Concerns\BelongsToResellerScope;
use Database\Factories\ResellerPackagePricingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ResellerPackagePricing extends Model
{
    /** @use HasFactory<ResellerPackagePricingFactory> */
    use BelongsToResellerScope, HasFactory, SoftDeletes;

    // "pricing" is uncountable — Eloquent's naïve pluralization would guess
    // reseller_package_pricings, which doesn't match the migration's table
    // name (reseller_package_pricing, matching docs/ROADMAP.md's naming).
    protected $table = 'reseller_package_pricing';

    protected $fillable = [
        'reseller_id',
        'name',
        'description',
        'price',
        'is_custom',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_custom' => 'boolean',
            'status' => ResellerPackagePricingStatus::class,
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }
}
