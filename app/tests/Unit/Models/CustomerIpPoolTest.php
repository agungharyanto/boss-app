<?php

namespace Tests\Unit\Models;

use App\Enums\NetworkProfileGroupType;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.14.5.4 Bagian B — CustomerIpPool::routerOsPoolName(): the pool name
 * sent to RouterOS auto-appends " (pool)" when it collides with a
 * `/ppp profile` name (a ppp-type Grup Profil's name) on the same NAS.
 * Real WinBox bug: RouterOS mis-resolves `remote-address` when a pool and
 * a profile share a name.
 */
class CustomerIpPoolTest extends TestCase
{
    use RefreshDatabase;

    private function nas(): Nas
    {
        return Nas::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
    }

    public function test_router_pool_name_is_verbatim_with_no_collision(): void
    {
        $nas = $this->nas();
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Pool-Unik']);

        $this->assertSame('Pool-Unik', $pool->routerOsPoolName());
    }

    public function test_router_pool_name_gets_a_suffix_when_a_ppp_grup_profil_on_the_same_nas_shares_the_name(): void
    {
        $nas = $this->nas();
        $poolA = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'PPPoE-Remote']);
        $group = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id, 'customer_ip_pool_id' => $poolA->id,
            'type' => NetworkProfileGroupType::Ppp, 'name' => 'PPPoE-Remote',
        ]);

        $this->assertSame('PPPoE-Remote (pool)', $poolA->fresh()->routerOsPoolName());
        // Kolom DB `name` tidak berubah.
        $this->assertSame('PPPoE-Remote', $poolA->fresh()->name);
        $this->assertNotNull($group);
    }

    public function test_no_suffix_when_the_same_named_grup_profil_is_hotspot_type(): void
    {
        $nas = $this->nas();
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Shared-Name']);
        NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id,
            'type' => NetworkProfileGroupType::Hotspot, 'name' => 'Shared-Name',
        ]);

        // Hotspot type pushes `/ip hotspot user profile`, a different
        // namespace — no `/ppp profile` collision.
        $this->assertSame('Shared-Name', $pool->fresh()->routerOsPoolName());
    }

    public function test_no_suffix_when_the_same_named_ppp_grup_profil_is_on_a_different_nas(): void
    {
        $nasA = $this->nas();
        $nasB = $this->nas();
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nasA->id, 'name' => 'Cross-Nas']);
        $poolB = CustomerIpPool::factory()->create(['nas_id' => $nasB->id]);
        NetworkProfileGroup::factory()->create([
            'nas_id' => $nasB->id, 'customer_ip_pool_id' => $poolB->id,
            'type' => NetworkProfileGroupType::Ppp, 'name' => 'Cross-Nas',
        ]);

        $this->assertSame('Cross-Nas', $pool->fresh()->routerOsPoolName());
    }
}
