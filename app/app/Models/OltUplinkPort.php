<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.23.5 Bagian 2 — cache status port uplink OLT (ON-DEMAND, pola
 * onu_registries). Baris hanya dibuat/diupdate lewat OltUplinkService::
 * refresh() — bukan sumber kebenaran tulis, bukan job berkala.
 */
class OltUplinkPort extends Model
{
    protected $fillable = [
        'olt_device_id', 'port_name', 'is_10g',
        'admin_state', 'oper_status', 'line_protocol', 'description',
        'negotiation', 'port_type', 'duplex', 'mtu',
        'wavelength_nm', 'rx_power_dbm', 'tx_power_dbm', 'temperature_c', 'module_type',
        'port_mode', 'pvid', 'untagged_vlans', 'tagged_vlans',
        'raw_payload', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_10g' => 'boolean',
            'mtu' => 'integer',
            'wavelength_nm' => 'integer',
            'rx_power_dbm' => 'decimal:3',
            'tx_power_dbm' => 'decimal:3',
            'temperature_c' => 'decimal:2',
            'pvid' => 'integer',
            'raw_payload' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function oltDevice(): BelongsTo
    {
        return $this->belongsTo(OltDevice::class);
    }
}
