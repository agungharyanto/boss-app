<?php

namespace App\Models;

use App\Enums\NasStatus;
use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\NasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Nas extends Model
{
    /** @use HasFactory<NasFactory> */
    use BelongsToResellerScope, BelongsToTenant, HasFactory;

    protected $table = 'nas';

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'name',
        'description',
        'mikrotik_ip',
        'tr069_management_subnet',
        'api_port',
        'api_username',
        'api_password',
        'radius_secret',
        'auth_port',
        'acct_port',
        'coa_port',
        'status',
        'last_ping_at',
        'timezone',
    ];

    protected $hidden = [
        'api_password',
        'radius_secret',
    ];

    protected function casts(): array
    {
        return [
            'api_password' => 'encrypted',
            'radius_secret' => 'encrypted',
            'status' => NasStatus::class,
            'last_ping_at' => 'datetime',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function vpnAccounts(): HasMany
    {
        return $this->hasMany(VpnAccount::class);
    }
}
