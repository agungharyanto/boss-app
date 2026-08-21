<?php

namespace App\Models;

use App\Enums\OltAccessProtocol;
use App\Enums\OltConnectionTestResult;
use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\OltDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OltDevice extends Model
{
    /** @use HasFactory<OltDeviceFactory> */
    use BelongsToResellerScope, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'name',
        'nas_id',
        'ip_address',
        'olt_model_id',
        'access_protocol',
        'telnet_port',
        'telnet_username',
        'telnet_password',
        'ssh_port',
        'ssh_username',
        'ssh_password',
        'snmp_version',
        'snmp_port',
        'snmp_ro_community',
        'snmp_rw_community',
        'last_connection_test_at',
        'last_connection_test_result',
        'last_connection_test_message',
        'notes',
    ];

    protected $hidden = [
        'telnet_password',
        'ssh_password',
        'snmp_ro_community',
        'snmp_rw_community',
    ];

    protected function casts(): array
    {
        return [
            'access_protocol' => OltAccessProtocol::class,
            'telnet_password' => 'encrypted',
            'ssh_password' => 'encrypted',
            'snmp_ro_community' => 'encrypted',
            'snmp_rw_community' => 'encrypted',
            'last_connection_test_at' => 'datetime',
            'last_connection_test_result' => OltConnectionTestResult::class,
        ];
    }

    public function nas(): BelongsTo
    {
        return $this->belongsTo(Nas::class);
    }

    public function oltModel(): BelongsTo
    {
        return $this->belongsTo(OltModel::class, 'olt_model_id');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }
}
