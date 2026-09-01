<?php

namespace App\Models;

use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\OdpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

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
        // v0.16.0 — added by the create_odps_table alter migration
        // (2026_09_01_100100), nullable, no DB/Model constraint (see
        // FiberTopologyService::isLossRequired() for where "wajib diisi
        // untuk ODP" actually gets enforced — a FormRequest, not here).
        'parent_type',
        'parent_id',
        'loss_in_db',
        'loss_out_db',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'total_ports' => 'integer',
            'loss_in_db' => 'decimal:2',
            'loss_out_db' => 'decimal:2',
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
     * v0.16.0 — the ODP half of the same parent-link morph FiberNode
     * carries (see FiberNode::parent()/childOdps() for the reverse side).
     * An ODP's parent is expected to be a FiberNode (e.g. the Closure/ODC
     * it splits off from) — see FiberNode's own docblock for why this
     * isn't enforced at the Model/DB layer either.
     */
    public function parent(): MorphTo
    {
        return $this->morphTo();
    }

    public function photos(): MorphMany
    {
        return $this->morphMany(FiberNodePhoto::class, 'owner');
    }

    public function splitters(): MorphMany
    {
        return $this->morphMany(Splitter::class, 'owner');
    }

    public function cablesAsFrom(): MorphMany
    {
        return $this->morphMany(FiberCable::class, 'from');
    }

    public function cablesAsTo(): MorphMany
    {
        return $this->morphMany(FiberCable::class, 'to');
    }

    /**
     * @return Collection<int, FiberCable>
     */
    public function fiberCablesAsEndpoint(): Collection
    {
        return $this->cablesAsFrom->concat($this->cablesAsTo);
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
