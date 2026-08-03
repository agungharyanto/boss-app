<?php

namespace App\Models;

use App\Enums\WorkOrderPhotoType;
use Database\Factories\WorkOrderPhotoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderPhoto extends Model
{
    /** @use HasFactory<WorkOrderPhotoFactory> */
    use HasFactory;

    protected $fillable = [
        'work_order_id',
        'type',
        'file_path',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => WorkOrderPhotoType::class,
            'uploaded_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
