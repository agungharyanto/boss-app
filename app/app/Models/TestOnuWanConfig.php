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
        'onu_mode',
        'wan_mode',
        'config_method',
    ];

    protected function casts(): array
    {
        return [
            'onu_mode' => TestOnuMode::class,
            'wan_mode' => TestOnuWanMode::class,
            'config_method' => TestOnuConfigMethod::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function attachedVlans(): HasMany
    {
        return $this->hasMany(TestOnuAttachedVlan::class);
    }
}
