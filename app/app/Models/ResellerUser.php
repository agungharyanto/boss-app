<?php

namespace App\Models;

use App\Enums\ResellerUserRole;
use App\Enums\ResellerUserStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ResellerUser extends Pivot
{
    protected $table = 'reseller_users';

    public $incrementing = true;

    protected $fillable = [
        'reseller_id',
        'user_id',
        'role',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'role' => ResellerUserRole::class,
            'status' => ResellerUserStatus::class,
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
