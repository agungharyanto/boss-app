<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ToolTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ToolType extends Model
{
    /** @use HasFactory<ToolTypeFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'category',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function toolUsages(): HasMany
    {
        return $this->hasMany(WorkOrderToolUsage::class);
    }
}
