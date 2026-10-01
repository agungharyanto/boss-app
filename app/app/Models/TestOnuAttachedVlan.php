<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.23.5 (Opsi B) — pivot VLAN yang diizinkan lewat ke ONU test (level
 * flow/vlan-filter permission, BUKAN WAN kedua). `vlan_id` adalah sumber
 * kebenaran; `network_profile_group_id` opsional (VLAN 9/mentah tidak
 * selalu punya grup). Lihat docs/omci/onu-test-ui-design.md.
 */
class TestOnuAttachedVlan extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'test_onu_wan_config_id',
        'network_profile_group_id',
        'vlan_id',
    ];

    protected function casts(): array
    {
        return [
            'vlan_id' => 'integer',
        ];
    }

    public function wanConfig(): BelongsTo
    {
        return $this->belongsTo(TestOnuWanConfig::class, 'test_onu_wan_config_id');
    }

    public function networkProfileGroup(): BelongsTo
    {
        return $this->belongsTo(NetworkProfileGroup::class);
    }
}
