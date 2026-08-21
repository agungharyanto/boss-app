<?php

namespace App\Models;

use Database\Factories\OltManufacturerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OltManufacturer extends Model
{
    /** @use HasFactory<OltManufacturerFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function models(): HasMany
    {
        return $this->hasMany(OltModel::class);
    }
}
