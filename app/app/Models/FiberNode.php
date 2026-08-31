<?php

namespace App\Models;

use App\Enums\FiberNodeType;
use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\FiberNodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * v0.16.0 — Core Network Infrastructure Management. A topology point other
 * than ODP (OTB/Closure/ODC) — ODP itself stays in the existing `odps`
 * table (v0.5.0), see that model's own docblock for its half of this same
 * morph wiring.
 *
 * `parent`/`childNodes`/`childOdps` — the first genuinely two-directional
 * morph relation in this codebase (see docs/ROADMAP.md's v0.16.0 Langkah 0
 * section: the only prior morph relation, ResellerTaxLedger::reference(),
 * is one-directional with no matching morphMany). Intended real-world shape
 * is OTB/Closure/ODC self-referencing (a Closure hangs off an OTB/ODC) with
 * an ODP hanging off a FiberNode — but the DB column itself is a plain,
 * unconstrained polymorphic pair (per Langkah 1 design), so nothing at the
 * Model layer enforces that shape either; it's just what these relations
 * are named for.
 */
class FiberNode extends Model
{
    /** @use HasFactory<FiberNodeFactory> */
    use BelongsToResellerScope, BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'node_type',
        'local_label',
        'parent_type',
        'parent_id',
        'latitude',
        'longitude',
        'loss_in_db',
        'loss_out_db',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'node_type' => FiberNodeType::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'loss_in_db' => 'decimal:2',
            'loss_out_db' => 'decimal:2',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function parent(): MorphTo
    {
        return $this->morphTo();
    }

    public function childNodes(): MorphMany
    {
        return $this->morphMany(self::class, 'parent');
    }

    public function childOdps(): MorphMany
    {
        return $this->morphMany(Odp::class, 'parent');
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
     * Convenience merge of both directional relations — a fiber_cables row
     * can have this node as either endpoint, and most real callers (a
     * splice-diagram, a "cables touching this point" list) don't care
     * which direction. Not itself a lazy Eloquent relation (can't be
     * eager-loaded via with()) — call cablesAsFrom()/cablesAsTo()
     * separately when eager-loading matters.
     *
     * @return Collection<int, FiberCable>
     */
    public function fiberCablesAsEndpoint(): Collection
    {
        return $this->cablesAsFrom->concat($this->cablesAsTo);
    }
}
