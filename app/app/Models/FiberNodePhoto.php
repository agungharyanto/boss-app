<?php

namespace App\Models;

use Database\Factories\FiberNodePhotoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * v0.16.0 — one photo per topology point, owner morphs to FiberNode OR
 * Odp (see FiberNode::photos()/Odp::photos() for the reverse side).
 */
class FiberNodePhoto extends Model
{
    /** @use HasFactory<FiberNodePhotoFactory> */
    use HasFactory;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'photo_path',
        'caption',
        'taken_at',
    ];

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
