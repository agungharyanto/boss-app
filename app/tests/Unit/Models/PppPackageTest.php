<?php

namespace Tests\Unit\Models;

use App\Enums\NetworkProfileGroupType;
use App\Models\CustomerIpPool;
use App\Models\HotspotPackage;
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
 */
class PppPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_timeout_always_reflects_masa_aktif_never_null(): void
    {
        $package = new PppPackage(['active_duration_value' => 3, 'active_duration_unit' => 'hour']);

        $this->assertSame('3h', $package->routerOsSessionTimeout());
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

    private function nasWithGroup(string $groupName, NetworkProfileGroupType $type = NetworkProfileGroupType::Ppp): NetworkProfileGroup
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        return NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id,
            'type' => $type, 'name' => $groupName,
        ]);
    }

    // ── ATURAN NAMA FINAL (2026-09-05): dunia PPP bebas senama, dunia
    //    Hotspot tetap tidak boleh bentrok ──────────────────────────────

    public function test_does_not_collide_with_a_ppp_grup_profil_name_on_the_same_nas(): void
    {
        $group = $this->nasWithGroup('HomeFixed-10Mbps');

        $this->assertFalse(PppPackage::collidesWithExistingName($group->nas_id, 'HomeFixed-10Mbps'));
    }

    public function test_does_not_collide_with_another_ppp_package_on_the_same_nas(): void
    {
        $groupA = $this->nasWithGroup('Grup-A');
        $poolB = CustomerIpPool::factory()->create(['nas_id' => $groupA->nas_id]);
        $groupB = NetworkProfileGroup::factory()->create([
            'nas_id' => $groupA->nas_id, 'customer_ip_pool_id' => $poolB->id,
            'type' => NetworkProfileGroupType::Ppp, 'name' => 'Grup-B', 'tenant_id' => $groupA->tenant_id,
        ]);
        PppPackage::factory()->create(['network_profile_group_id' => $groupB->id, 'name' => 'Paket-Bulanan-10Mbps']);

        $this->assertFalse(PppPackage::collidesWithExistingName($groupA->nas_id, 'Paket-Bulanan-10Mbps'));
    }

    public function test_collides_with_a_hotspot_grup_profil_name_on_the_same_nas(): void
    {
        $hotspotGroup = $this->nasWithGroup('TOKEN-1Hari', NetworkProfileGroupType::Hotspot);

        $this->assertTrue(PppPackage::collidesWithExistingName($hotspotGroup->nas_id, 'TOKEN-1Hari'));
    }

    public function test_collides_with_a_hotspot_package_name_on_the_same_nas(): void
    {
        $hotspotGroup = $this->nasWithGroup('Grup-Hotspot', NetworkProfileGroupType::Hotspot);
        HotspotPackage::factory()->create([
            'network_profile_group_id' => $hotspotGroup->id, 'name' => 'Voucher-3Jam',
        ]);

        $this->assertTrue(PppPackage::collidesWithExistingName($hotspotGroup->nas_id, 'Voucher-3Jam'));
    }

    public function test_does_not_collide_with_a_hotspot_name_on_a_different_nas(): void
    {
        $hotspotGroup = $this->nasWithGroup('TOKEN-1Hari', NetworkProfileGroupType::Hotspot);
        $otherNas = Nas::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        $this->assertFalse(PppPackage::collidesWithExistingName($otherNas->id, 'TOKEN-1Hari'));
        $this->assertNotSame($hotspotGroup->nas_id, $otherNas->id);
    }

    // ── routerOsProfileName(): auto-differentiate nama yang dikirim ke router ──

    public function test_router_name_is_verbatim_when_no_ppp_collision(): void
    {
        $group = $this->nasWithGroup('SomethingUnique');
        $package = PppPackage::factory()->create([
            'network_profile_group_id' => $group->id, 'name' => 'Paket-Sendirian',
        ]);

        $this->assertSame('Paket-Sendirian', $package->fresh()->routerOsProfileName());
    }

    public function test_router_name_gets_a_suffix_when_it_matches_the_parent_ppp_grup_profil(): void
    {
        $group = $this->nasWithGroup('test-10Mbps-HomeFixed');
        $package = PppPackage::factory()->create([
            'network_profile_group_id' => $group->id, 'name' => 'test-10Mbps-HomeFixed',
        ]);

        $fresh = $package->fresh();
        // Nama TAMPILAN (kolom DB) tidak berubah.
        $this->assertSame('test-10Mbps-HomeFixed', $fresh->name);
        // Nama yang GENUINELY dikirim ke router beda.
        $this->assertSame("test-10Mbps-HomeFixed (pkg #{$fresh->id})", $fresh->routerOsProfileName());
    }

    public function test_router_name_suffix_only_applies_to_the_higher_id_package_when_two_packages_share_a_name(): void
    {
        $group = $this->nasWithGroup('AnchorGroup');
        $first = PppPackage::factory()->create(['network_profile_group_id' => $group->id, 'name' => 'Kembar']);
        $poolB = CustomerIpPool::factory()->create(['nas_id' => $group->nas_id]);
        $groupB = NetworkProfileGroup::factory()->create([
            'nas_id' => $group->nas_id, 'customer_ip_pool_id' => $poolB->id,
            'type' => NetworkProfileGroupType::Ppp, 'name' => 'GrupB', 'tenant_id' => $group->tenant_id,
        ]);
        $second = PppPackage::factory()->create(['network_profile_group_id' => $groupB->id, 'name' => 'Kembar']);

        // Yang dibuat duluan (id lebih kecil) tetap verbatim.
        $this->assertSame('Kembar', $first->fresh()->routerOsProfileName());
        // Yang belakangan dapat suffix.
        $this->assertSame("Kembar (pkg #{$second->id})", $second->fresh()->routerOsProfileName());
    }
}
