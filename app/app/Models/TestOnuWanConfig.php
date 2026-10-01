<?php

namespace App\Models;

use App\Enums\TestOnuConfigMethod;
use App\Enums\TestOnuMode;
use App\Enums\TestOnuWanMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * v0.23.5 (Opsi B) — konfigurasi WAN test per customer is_test_fixture.
 * TEST-ONLY (TestCredentialSyncService menolak keras non-test-fixture).
 * Lihat docs/omci/onu-test-ui-design.md.
 *
 * VLAN 9 (remote management) SELALU ada di attachedVlans dan tidak bisa
 * dihapus — di-enforce di TestCredentialSyncService (konstanta
 * MANDATORY_ATTACHED_VLAN), bukan hanya UI.
 */
class TestOnuWanConfig extends Model
{
    protected $fillable = [
        'customer_id',
        'package_id',
        'vlan_pppoe',
        'onu_mode',
        'wan_mode',
        'config_method',
        'pppoe_username',
        'pppoe_password',
    ];

    protected function casts(): array
    {
        return [
            'vlan_pppoe' => 'integer',
            'onu_mode' => TestOnuMode::class,
            'wan_mode' => TestOnuWanMode::class,
            'config_method' => TestOnuConfigMethod::class,
            // Pola enkripsi sama OltDevice.telnet_password/ssh_password.
            'pppoe_password' => 'encrypted',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(PppPackage::class, 'package_id');
    }

    public function attachedVlans(): HasMany
    {
        return $this->hasMany(TestOnuAttachedVlan::class);
    }
}
