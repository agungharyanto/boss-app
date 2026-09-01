<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SalesRouteNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.16.0 Langkah 11 — see the migration docblock. Reference-only; no
 * billing/invoice code ever touches this table.
 */
class SalesRouteNote extends Model
{
    /** @use HasFactory<SalesRouteNoteFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'prospect_name',
        'prospect_address',
        'from_latitude',
        'from_longitude',
        'target_odp_id',
        'route_label',
        'route_geometry',
        'distance_meters',
        'is_straight_line_estimate',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'from_latitude' => 'decimal:7',
            'from_longitude' => 'decimal:7',
            'route_geometry' => 'array',
            'distance_meters' => 'integer',
            'is_straight_line_estimate' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function targetOdp(): BelongsTo
    {
        return $this->belongsTo(Odp::class, 'target_odp_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
