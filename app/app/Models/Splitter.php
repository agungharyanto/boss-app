<?php

namespace App\Models;

use Database\Factories\SplitterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * v0.16.0 — a splitter attached to one topology point (owner morphs to
 * FiberNode OR Odp). `ratio` is a free string (e.g. "1:8") rather than an
 * enum — real splitter ratios vary and aren't fully known in advance; see
 * App\Services\Network\SplitterLossReferenceService for the non-blocking
 * expected-loss reference lookup keyed on this same string.
 *
 * No tenant_id of its own (scoped implicitly through its polymorphic
 * owner, by design — see the create_splitters_table migration's own
 * docblock) — deliberately NOT a BelongsToTenant global scope, since that
 * trait's own auto-fill-on-create logic assumes a real tenant_id column.
 * scopeTenantScoped() below is the manual equivalent, added in Langkah 4
 * after a real cross-tenant leak was found: SplitterController::index()
 * (Langkah 3) queried Splitter::query() completely unscoped.
 */
class Splitter extends Model
{
    /** @use HasFactory<SplitterFactory> */
    use HasFactory;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'ratio',
        'model',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function accessories(): HasMany
    {
        return $this->hasMany(FiberAccessory::class);
    }

    /**
     * Restricts to splitters whose owner (FiberNode or Odp) belongs to the
     * current tenant — both of those models already enforce this via their
     * own BelongsToTenant global scope, so the subqueries below inherit it
     * automatically rather than re-deriving Auth::user()->tenant_id here.
     */
    public function scopeTenantScoped(Builder $query): Builder
    {
        return $query->where(function (Builder $outer) {
            $outer->where(fn (Builder $q) => $q->where('owner_type', FiberNode::class)->whereIn('owner_id', FiberNode::query()->select('id')))
                ->orWhere(fn (Builder $q) => $q->where('owner_type', Odp::class)->whereIn('owner_id', Odp::query()->select('id')));
        });
    }
}
