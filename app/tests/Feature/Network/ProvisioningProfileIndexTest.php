<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\CpeProvisioningOverride;
use App\Livewire\Network\ProvisioningProfileIndex;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\PppPackage;
use App\Models\ProvisioningProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\Provisioning\ProvisioningProfileService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.23.8.6b — CRUD Profil Provisioning. Nol push ke device.
 */
class ProvisioningProfileIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @return array{0:Tenant,1:User} */
    private function adminTenant(): array
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');

        return [$tenant, $admin];
    }

    private function makeProfile(User $admin, array $profileOverrides = [], array $ssids = [], ?array $bridge = null): ProvisioningProfile
    {
        $this->actingAs($admin);

        return app(ProvisioningProfileService::class)->create(
            array_merge(['name' => 'P1', 'slug' => 'p1-'.uniqid(), 'description' => null, 'enabled' => false], $profileOverrides),
            ['wan_mode' => 'pppoe', 'vlan' => 10, 'nat_enabled' => true, 'service_type' => 'INTERNET', 'wifi_role' => 'primary_wifi',
                'pppoe_username_strategy' => 'derive_from_cid', 'pppoe_username_static' => null,
                'pppoe_password_strategy' => 'constant', 'pppoe_password_static' => ''],
            $bridge,
            $ssids,
            [],
        );
    }

    public function test_create_profile_with_internet_bridge_ssid_and_package_link(): void
    {
        [$tenant, $admin] = $this->adminTenant();
        $pkg = PppPackage::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($admin)->test(ProvisioningProfileIndex::class)
            ->call('openCreate')
            ->set('name', 'Profil A')
            ->set('slug', 'profil-a')
            ->set('wanMode', 'pppoe')->set('wanVlan', '10')->set('wanWifiRole', 'primary_wifi')
            ->set('hasBridge', true)->set('bridgeVlan', '172')->set('bridgeWifiRole', 'guest_wifi')
            ->set('ssids', [['wifi_role' => 'guest_wifi', 'band' => '2.4g', 'ssid_name' => 'TOKEN WIFI', 'security_mode' => 'open', 'password' => '']])
            ->set('linkedPackageIds', [$pkg->id])
            ->set('defaultPackageIds', [$pkg->id])
            ->call('save')
            ->assertHasNoErrors();

        $profile = ProvisioningProfile::where('slug', 'profil-a')->firstOrFail();
        $this->assertSame(10, $profile->wanInternet->vlan);
        $this->assertSame(172, $profile->wanBridge->vlan);
        $this->assertCount(1, $profile->ssids);
        $this->assertTrue((bool) $profile->pppPackages()->first()->pivot->is_default);
    }

    public function test_vlan_out_of_range_rejected(): void
    {
        [, $admin] = $this->adminTenant();
        Livewire::actingAs($admin)->test(ProvisioningProfileIndex::class)
            ->call('openCreate')->set('name', 'X')->set('slug', 'x')->set('wanVlan', '5000')
            ->call('save')->assertHasErrors('wanVlan');
    }

    public function test_internet_and_bridge_vlan_cannot_be_same(): void
    {
        [, $admin] = $this->adminTenant();
        Livewire::actingAs($admin)->test(ProvisioningProfileIndex::class)
            ->call('openCreate')->set('name', 'X')->set('slug', 'x')
            ->set('wanVlan', '10')->set('hasBridge', true)->set('bridgeVlan', '10')->set('bridgeWifiRole', 'guest_wifi')
            ->call('save')->assertHasErrors('bridgeVlan');
    }

    public function test_duplicate_ssid_role_band_rejected(): void
    {
        [, $admin] = $this->adminTenant();
        Livewire::actingAs($admin)->test(ProvisioningProfileIndex::class)
            ->call('openCreate')->set('name', 'X')->set('slug', 'x')->set('wanVlan', '10')
            ->set('ssids', [
                ['wifi_role' => 'guest_wifi', 'band' => '2.4g', 'ssid_name' => 'A', 'security_mode' => 'open', 'password' => ''],
                ['wifi_role' => 'guest_wifi', 'band' => '2.4g', 'ssid_name' => 'B', 'security_mode' => 'open', 'password' => ''],
            ])
            ->call('save')->assertHasErrors('ssids.1.band');
    }

    public function test_new_wpa2_ssid_requires_password(): void
    {
        [, $admin] = $this->adminTenant();
        Livewire::actingAs($admin)->test(ProvisioningProfileIndex::class)
            ->call('openCreate')->set('name', 'X')->set('slug', 'x')->set('wanVlan', '10')
            ->set('ssids', [['wifi_role' => 'primary_wifi', 'band' => '2.4g', 'ssid_name' => 'A', 'security_mode' => 'wpa2', 'password' => '']])
            ->call('save')->assertHasErrors('ssids.0.password');
    }

    public function test_edit_does_not_prefill_or_leak_passwords(): void
    {
        [, $admin] = $this->adminTenant();
        $profile = $this->makeProfile(
            $admin,
            [],
            [['wifi_role' => 'primary_wifi', 'band' => '2.4g', 'ssid_name' => 'A', 'security_mode' => 'wpa2', 'password' => 'secret123']],
        );
        // PPPoE static password juga di-set langsung (lewat service di makeProfile pakai constant;
        // set manual di sini utk uji kebocoran).
        $profile->wanInternet->forceFill(['pppoe_password_strategy' => 'static', 'pppoe_password_static' => 'pppsecret'])->save();

        Livewire::actingAs($admin)->test(ProvisioningProfileIndex::class)
            ->call('edit', $profile->id)
            ->assertSet('ssids.0.password', '')
            ->assertSet('pppoePasswordStatic', '')
            ->assertDontSee('secret123')
            ->assertDontSee('pppsecret');
    }

    public function test_blank_password_on_edit_keeps_stored_value(): void
    {
        [, $admin] = $this->adminTenant();
        $profile = $this->makeProfile(
            $admin,
            [],
            [['wifi_role' => 'primary_wifi', 'band' => '2.4g', 'ssid_name' => 'A', 'security_mode' => 'wpa2', 'password' => 'secret123']],
        );

        Livewire::actingAs($admin)->test(ProvisioningProfileIndex::class)
            ->call('edit', $profile->id)
            ->set('ssids.0.ssid_name', 'A-renamed')   // ubah nama, password dibiarkan kosong
            ->call('save')->assertHasNoErrors();

        $ssid = $profile->ssids()->first();
        $this->assertSame('A-renamed', $ssid->ssid_name);
        $this->assertSame('secret123', $ssid->password);  // tetap (encrypted cast → decrypt)
    }

    public function test_delete_nulls_cpe_override(): void
    {
        [$tenant, $admin] = $this->adminTenant();
        $profile = $this->makeProfile($admin);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $cpe = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'provisioning_profile_id' => $profile->id]);

        Livewire::actingAs($admin)->test(ProvisioningProfileIndex::class)
            ->call('delete', $profile->id);

        $this->assertNull($cpe->fresh()->provisioning_profile_id);
    }

    public function test_tenant_isolation_list_hides_other_tenant_profiles(): void
    {
        [, $adminA] = $this->adminTenant();
        $this->makeProfile($adminA, ['name' => 'RahasiaTenantA', 'slug' => 'rahasia-a']);

        [, $adminB] = $this->adminTenant();
        Livewire::actingAs($adminB)->test(ProvisioningProfileIndex::class)
            ->assertDontSee('RahasiaTenantA');
    }

    public function test_view_only_user_cannot_open_create(): void
    {
        $tenant = Tenant::factory()->create();
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo('provisioning_profiles.view');

        Livewire::actingAs($viewer)->test(ProvisioningProfileIndex::class)
            ->assertSet('showForm', false)
            ->call('openCreate')->assertForbidden();
    }

    public function test_ssid_sample_resolve_uses_a_sample_customer(): void
    {
        [$tenant, $admin] = $this->adminTenant();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);  // cid auto-generate observer

        $resolved = Livewire::actingAs($admin)->test(ProvisioningProfileIndex::class)
            ->instance()->ssidSampleResolve('WiFi-{cid}');

        $this->assertStringContainsString((string) $customer->fresh()->cid, $resolved);
    }

    public function test_cpe_override_saves_and_resolver_reports_source(): void
    {
        [$tenant, $admin] = $this->adminTenant();
        $profile = $this->makeProfile($admin, ['name' => 'OverrideProfil', 'slug' => 'ovr']);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $cpe = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        Livewire::actingAs($admin)->test(CpeProvisioningOverride::class, ['cpeDevice' => $cpe])
            ->set('profileId', (string) $profile->id)
            ->call('save')->assertHasNoErrors()
            ->assertSee('OverrideProfil')
            ->assertSee('override_cpe');

        $this->assertSame($profile->id, $cpe->fresh()->provisioning_profile_id);
    }
}
