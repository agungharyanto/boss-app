<?php

namespace App\Models;

use App\Enums\OltPonType;
use Database\Factories\OltModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OltModel extends Model
{
    /** @use HasFactory<OltModelFactory> */
    use HasFactory;

    protected $table = 'olt_models';

    protected $fillable = [
        'olt_manufacturer_id',
        'name',
        'supported_pon_type',
    ];

    protected function casts(): array
    {
        return [
            'supported_pon_type' => OltPonType::class,
        ];
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(OltManufacturer::class, 'olt_manufacturer_id');
    }
}
