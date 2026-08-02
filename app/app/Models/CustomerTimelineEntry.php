<?php

namespace App\Models;

use Database\Factories\CustomerTimelineEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerTimelineEntry extends Model
{
    /** @use HasFactory<CustomerTimelineEntryFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'event_type',
        'description',
        'changes',
        'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
