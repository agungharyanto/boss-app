<?php

namespace App\Models;

use Database\Factories\SplitterFactory;
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
}
