<?php

namespace App\Models;

use App\Enums\ContactAccessLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerContact extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerContactFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'name',
        'phone_number',
        'relationship',
        'access_level',
        'can_view_billing',
        'can_request_service_change',
        'can_receive_notifications',
        'is_authorized_contact',
    ];

    protected function casts(): array
    {
        return [
            'access_level' => ContactAccessLevel::class,
            'can_view_billing' => 'boolean',
            'can_request_service_change' => 'boolean',
            'can_receive_notifications' => 'boolean',
            'is_authorized_contact' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
