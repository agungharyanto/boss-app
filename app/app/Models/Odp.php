<?php

namespace App\Models;

use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\OdpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Odp extends Model
{
    /** @use HasFactory<OdpFactory> */
    use BelongsToResellerScope, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'code',
        'name',
        'latitude',
        'longitude',
        'total_ports',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'total_ports' => 'integer',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function ports(): HasMany
    {
        return $this->hasMany(OdpPort::class);
    }

    /**
     * Creates port_number 1..total_ports as available OdpPort rows —
     * called explicitly by OdpController::store() right after a real ODP
     * is created via the API. Deliberately NOT a model `created` event:
     * that would silently fire for every Odp::factory()->create() in
     * tests too, colliding with OdpPortFactory's own independently-created
     * ports on the unique(odp_id, port_number) constraint.
     */
    public function provisionPorts(): void
    {
        collect(range(1, $this->total_ports))->each(
            fn (int $portNumber) => $this->ports()->create(['port_number' => $portNumber])
        );
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
