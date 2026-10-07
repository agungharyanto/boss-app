<?php

namespace Tests\Feature\Network;

use App\Enums\WanMode;
use App\Enums\WifiRole;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\PppPackage;
use App\Models\ProvisioningProfile;
use App\Models\ProvisioningWanBridge;
use App\Models\ProvisioningWanInternet;
use App\Models\Tenant;
use App\Services\Network\Provisioning\ProvisioningProfileResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.23.8.3 — skema template inti + resolver (nol sentuh device).
 */
class ProvisioningTemplateResolverTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
    }

    private function profile(string $slug): ProvisioningProfile
    {
        return ProvisioningProfile::create([
            'tenant_id' => $this->tenant->id,
            'name' => $slug,
            'slug' => $slug,
            'enabled' => true,
        ]);
    }

    private function package(): PppPackage
    {
        return PppPackage::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function cpeFor(?PppPackage $package, ?ProvisioningProfile $override = null): CpeDevice
    {
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'ppp_package_id' => $package?->id,
        ]);

        return CpeDevice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'provisioning_profile_id' => $override?->id,
        ]);
    }

    private function resolver(): ProvisioningProfileResolver
    {
        return app(ProvisioningProfileResolver::class);
    }

    // ---- skema + model ----------------------------------------------------

    public function test_untested_flag_synced_from_wan_mode(): void
    {
        $p = $this->profile('p');

        $pppoe = ProvisioningWanInternet::create([
            'provisioning_profile_id' => $p->id, 'wan_mode' => WanMode::Pppoe, 'vlan' => 10,
        ]);
        $this->assertFalse($pppoe->fresh()->untested_on_hardware);

        $p2 = $this->profile('p2');
        $dhcp = ProvisioningWanInternet::create([
            'provisioning_profile_id' => $p2->id, 'wan_mode' => WanMode::Dhcp, 'vlan' => 20,
        ]);
        $this->assertTrue($dhcp->fresh()->untested_on_hardware);

        // Ganti mode -> flag ikut ter-sync di saving.
        $dhcp->update(['wan_mode' => WanMode::Pppoe]);
        $this->assertFalse($dhcp->fresh()->untested_on_hardware);
    }

    public function test_profile_relations(): void
    {
        $p = $this->profile('home');
        ProvisioningWanInternet::create(['provisioning_profile_id' => $p->id, 'wan_mode' => WanMode::Pppoe, 'vlan' => 10, 'wifi_role' => WifiRole::PrimaryWifi]);
        ProvisioningWanBridge::create(['provisioning_profile_id' => $p->id, 'vlan' => 172, 'wifi_role' => WifiRole::GuestWifi]);
        $pkg = $this->package();
        $p->pppPackages()->attach($pkg->id, ['is_default' => true]);

        $p->refresh();
        $this->assertInstanceOf(ProvisioningWanInternet::class, $p->wanInternet);
        $this->assertSame(WifiRole::PrimaryWifi, $p->wanInternet->wifi_role);
        $this->assertInstanceOf(ProvisioningWanBridge::class, $p->wanBridge);
        $this->assertSame(172, $p->wanBridge->vlan);
        $this->assertTrue($p->pppPackages->contains($pkg));
        $this->assertTrue((bool) $p->pppPackages->first()->pivot->is_default);
        $this->assertTrue($pkg->provisioningProfiles->contains($p));
    }

    // ---- resolver: urutan prioritas --------------------------------------

    public function test_resolver_cpe_override_wins_over_package(): void
    {
        $pkg = $this->package();
        $pkgProfile = $this->profile('pkg');
        $pkg->provisioningProfiles()->attach($pkgProfile->id, ['is_default' => true]);
        $override = $this->profile('override');

        $cpe = $this->cpeFor($pkg, $override);
        $result = $this->resolver()->resolveForCpe($cpe);

        $this->assertSame('override_cpe', $result->source);
        $this->assertTrue($result->profile->is($override));
    }

    // Catatan: skenario 'override_missing' (branch defensif di resolver) TIDAK
    // bisa diuji — FK cpe_devices.provisioning_profile_id + nullOnDelete menjamin
    // override tak pernah dangling (hapus profil -> override jadi NULL, bukan id
    // mati). Branch dipertahankan sebagai defense-in-depth, bukan jalur nyata.

    public function test_resolver_package_default_wins_over_other_profiles(): void
    {
        $pkg = $this->package();
        $a = $this->profile('a');
        $b = $this->profile('b');
        $pkg->provisioningProfiles()->attach($a->id, ['is_default' => false]);
        $pkg->provisioningProfiles()->attach($b->id, ['is_default' => true]);

        $result = $this->resolver()->resolveForCpe($this->cpeFor($pkg));
        $this->assertSame('package_default', $result->source);
        $this->assertTrue($result->profile->is($b));
    }

    public function test_resolver_single_package_profile(): void
    {
        $pkg = $this->package();
        $only = $this->profile('only');
        $pkg->provisioningProfiles()->attach($only->id, ['is_default' => false]);

        $result = $this->resolver()->resolveForCpe($this->cpeFor($pkg));
        $this->assertSame('package_single', $result->source);
        $this->assertTrue($result->profile->is($only));
    }

    public function test_resolver_ambiguous_multiple_without_default(): void
    {
        $pkg = $this->package();
        $pkg->provisioningProfiles()->attach($this->profile('x')->id, ['is_default' => false]);
        $pkg->provisioningProfiles()->attach($this->profile('y')->id, ['is_default' => false]);

        $result = $this->resolver()->resolveForCpe($this->cpeFor($pkg));
        $this->assertNull($result->profile);
        $this->assertSame('ambiguous', $result->source);
        $this->assertStringContainsString('pilih profil eksplisit', $result->reason);
    }

    public function test_resolver_no_profile_on_package(): void
    {
        $result = $this->resolver()->resolveForCpe($this->cpeFor($this->package()));
        $this->assertNull($result->profile);
        $this->assertSame('no_profile', $result->source);
    }

    public function test_resolver_no_package(): void
    {
        $result = $this->resolver()->resolveForCpe($this->cpeFor(null));
        $this->assertNull($result->profile);
        $this->assertSame('no_package', $result->source);
    }
}
