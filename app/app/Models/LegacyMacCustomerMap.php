<?php

namespace App\Models;

use Database\Factories\LegacyMacCustomerMapFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A plain reference table (not tenant-scoped) loaded once from MixRadius'
 * radacct session history — see App\Console\Commands\ImportLegacyMacReference
 * and App\Services\Network\LegacyDeviceMatcherService, the only writer and
 * only reader respectively.
 */
class LegacyMacCustomerMap extends Model
{
    /** @use HasFactory<LegacyMacCustomerMapFactory> */
    use HasFactory;

    // Eloquent's default pluralization would guess "legacy_mac_customer_maps"
    // (pluralizing "map") — the table is deliberately named singular per its
    // own migration, so this must be spelled out explicitly.
    protected $table = 'legacy_mac_customer_map';

    protected $fillable = [
        'mac_address',
        'legacy_username',
    ];
}
