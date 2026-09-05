<?php

namespace Tests\Unit\Models;

use App\Enums\NetworkProfileGroupType;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.14.5 — pure logic unit tests for PppPackage::routerOsSessionTimeout()
 * (no DB), plus RefreshDatabase-backed tests for
 * PppPackage::collidesWithExistingName() (the real query logic behind the
 * cross-table name-collision check — see the migration's own docblock for
 * why this exists). The RouterOS command shape (session-timeout format)
 * was separately verified for real against ro-hotspot.bajastu.id — see
 * CLAUDE.md's v0.14.5 section.
 *
 * Revisi 2026-09-05 — `active_duration_value = 0` = Unlimited; test
 * "never null" diganti dengan test yang membuktikan 0 -> null.
 */
class PppPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_timeout_reflects_masa_aktif_for_a_real_duration(): void
    {
        $package = new PppPackage(['active_duration_value' => 3, 'active_duration_unit' => 'hour']);

        $this->assertSame('3h', $package->routerOsSessionTimeout());
    }

    public function test_zero_masa_aktif_means_unlimited_and_session_timeout_is_null(): void
    {
        $package = new PppPackage(['active_duration_value' => 0, 'active_duration_unit' => 'month']);

        $this->assertTrue($package->isUnlimitedDuration());
        $this->assertNull($package->routerOsSessionTimeout());
    }

    public function test_non_zero_masa_aktif_is_not_unlimited(): void
    {
        $package = new PppPackage(['active_duration_value' => 1, 'active_duration_unit' => 'month']);

        $this->assertFalse($package->isUnlimitedDuration());
    }

    public function test_session_timeout_converts_month_to_30_days(): void
    {
        $package = new PppPackage(['active_duration_value' => 2, 'active_duration_unit' => 'month']);

        $this->assertSame('60d', $package->routerOsSessionTimeout());
    }

    public function test_session_timeout_supports_minute_and_day_units(): void
    {
        $this->assertSame('90m', (new PppPackage(['active_duration_value' => 90, 'active_duration_unit' => 'minute']))->routerOsSessionTimeout());
        $this->assertSame('7d', (new PppPackage(['active_duration_value' => 7, 'active_duration_unit' => 'day']))->routerOsSessionTimeout());
    }

    private function nasWithGroup(string $groupName): NetworkProfileGroup
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        return NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id,
            'type' => NetworkProfileGroupType::Ppp, 'name' => $groupName,
        ]);
    }

    public function test_collides_with_a_sibling_grup_profil_name_on_the_same_nas(): void
    {
        $group = $this->nasWithGroup('HomeFixed-10Mbps');

        $this->assertTrue(PppPackage::collidesWithExistingName($group->nas_id, 'HomeFixed-10Mbps'));
    }

    public function test_does_not_collide_with_a_grup_profil_name_on_a_different_nas(): void
    {
        $group = $this->nasWithGroup('HomeFixed-10Mbps');
        $otherTenant = Tenant::factory()->create();
        $otherNas = Nas::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->assertFalse(PppPackage::collidesWithExistingName($otherNas->id, 'HomeFixed-10Mbps'));
        // sanity check the fixture itself is real
        $this->assertNotSame($group->nas_id, $otherNas->id);
    }

    public function test_collides_with_another_ppp_package_under_a_sibling_grup_profil_on_the_same_nas(): void
    {
        $groupA = $this->nasWithGroup('Grup-A');
        $tenant = $groupA->tenant_id;
        $nasId = $groupA->nas_id;
        $poolB = CustomerIpPool::factory()->create(['nas_id' => $nasId]);
        $groupB = NetworkProfileGroup::factory()->create([
            'nas_id' => $nasId, 'customer_ip_pool_id' => $poolB->id,
            'type' => NetworkProfileGroupType::Ppp, 'name' => 'Grup-B', 'tenant_id' => $tenant,
        ]);
        PppPackage::factory()->create(['network_profile_group_id' => $groupB->id, 'name' => 'Paket-Bulanan-10Mbps']);

        $this->assertTrue(PppPackage::collidesWithExistingName($nasId, 'Paket-Bulanan-10Mbps'));
    }

    public function test_does_not_collide_with_its_own_current_name_when_ignored(): void
    {
        $group = $this->nasWithGroup('Grup-A');
        $package = PppPackage::factory()->create(['network_profile_group_id' => $group->id, 'name' => 'Paket-Milik-Sendiri']);

        $this->assertFalse(PppPackage::collidesWithExistingName($group->nas_id, 'Paket-Milik-Sendiri', $package->id));
    }

    public function test_still_collides_with_a_different_packages_name_even_when_ignoring_self(): void
    {
        $group = $this->nasWithGroup('Grup-A');
        $selfPackage = PppPackage::factory()->create(['network_profile_group_id' => $group->id, 'name' => 'Paket-Sendiri']);
        PppPackage::factory()->create(['network_profile_group_id' => $group->id, 'name' => 'Paket-Lain']);

        $this->assertTrue(PppPackage::collidesWithExistingName($group->nas_id, 'Paket-Lain', $selfPackage->id));
    }
}
