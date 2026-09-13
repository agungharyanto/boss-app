<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\WanConfigPushLookup;
use App\Models\BandwidthProfile;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WanConfigTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * v0.12.6 (revisi) Bagian 2 — section "Push Konfig" pada /remote-config.
 * Reuse App\Services\Network\WanConfigPushService secara nyata (bukan
 * mocked) — asersi utama adalah SATU baris cpe_action_logs genuinely
 * tercatat lewat jalur yang sama dengan Detail Perangkat CPE, bukan
 * mekanisme push kedua yang terpisah.
 */
class WanConfigPushLookupLivewireTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $u->assignRole($role);

        return $u;
    }

    private function package(): PppPackage
    {
        $group = NetworkProfileGroup::factory()->create(['tenant_id' => $this->tenant->id]);

        return PppPackage::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $this->tenant->id])->id,
        ]);
    }

    public function test_page_is_forbidden_without_the_permission(): void
    {
        Livewire::actingAs($this->user('customer_service'))
            ->test(WanConfigPushLookup::class)
            ->assertForbidden();
    }

    public function test_search_finds_device_by_customer_name_cid_phone_or_serial_number(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Dita Ari Rianis Saumi']);
        $device = CpeDevice::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'serial_number' => 'ZICG298C09DB']);

        foreach (['Dita', $customer->cid, $customer->phone_number, 'ZICG298C09DB'] as $needle) {
            Livewire::actingAs($this->user('superadmin'))
                ->test(WanConfigPushLookup::class)
                ->set('search', $needle)
                ->assertSee($device->serial_number);
        }
    }

    public function test_search_does_not_leak_a_device_from_another_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherCustomer = Customer::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'Pelanggan Tenant Lain']);
        CpeDevice::factory()->create(['tenant_id' => $otherTenant->id, 'customer_id' => $otherCustomer->id, 'serial_number' => 'OUTSIDE-SN']);

        Livewire::actingAs($this->user('superadmin'))
            ->test(WanConfigPushLookup::class)
            ->set('search', 'Pelanggan Tenant Lain')
            ->assertDontSee('OUTSIDE-SN');
    }

    public function test_selecting_a_device_with_no_matching_template_shows_the_amber_message_and_disables_the_button(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'ppp_package_id' => $this->package()->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);

        Livewire::actingAs($this->user('superadmin'))
            ->test(WanConfigPushLookup::class)
            ->call('selectDevice', $device->id)
            ->assertSet('selectedDeviceId', $device->id)
            ->assertSee(__('Tidak ada Template yang cocok untuk kombinasi Paket + Tipe Modem device ini.'))
            ->assertSeeHtml('disabled');
    }

    public function test_selecting_a_device_with_a_matching_template_shows_it_and_enables_the_button(): void
    {
        $package = $this->package();
        $template = WanConfigTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'ppp_package_id' => $package->id,
            'modem_type_id' => null,
            'wan1_enabled' => true,
            'name' => 'Template PPPoE-Remote',
        ]);
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'ppp_package_id' => $package->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);

        Livewire::actingAs($this->user('superadmin'))
            ->test(WanConfigPushLookup::class)
            ->call('selectDevice', $device->id)
            ->assertSee($template->name)
            ->assertDontSee(__('Tidak ada Template yang cocok untuk kombinasi Paket + Tipe Modem device ini.'));
    }

    public function test_push_now_writes_a_cpe_action_log_via_the_real_wan_config_push_service(): void
    {
        $package = $this->package();
        WanConfigTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'ppp_package_id' => $package->id,
            'modem_type_id' => null,
            'wan1_enabled' => true,
        ]);
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'ppp_package_id' => $package->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);

        $actor = $this->user('superadmin');

        Livewire::actingAs($actor)
            ->test(WanConfigPushLookup::class)
            ->call('selectDevice', $device->id)
            ->call('pushNow')
            ->assertSet('pushWasError', false)
            ->assertSee(__('Push Konfig dilewati').':', false); // device belum pernah connect ke GenieACS -> skip, bukan error

        $this->assertDatabaseHas('cpe_action_logs', [
            'cpe_device_id' => $device->id,
            'action_type' => 'push_wan_config',
            'status' => 'skipped',
            'performed_by' => $actor->id,
        ]);
    }

    public function test_a_user_without_manage_permission_gets_a_403_calling_push_now(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);

        $viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'remote_config.view', 'guard_name' => 'web']));

        Livewire::actingAs($viewer)
            ->test(WanConfigPushLookup::class)
            ->call('selectDevice', $device->id)
            ->call('pushNow')
            ->assertForbidden();
    }

    public function test_selecting_a_device_from_another_tenant_404s(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherCustomer = Customer::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherDevice = CpeDevice::factory()->create(['tenant_id' => $otherTenant->id, 'customer_id' => $otherCustomer->id]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->user('superadmin'))
            ->test(WanConfigPushLookup::class)
            ->call('selectDevice', $otherDevice->id);
    }

    public function test_clearing_selection_resets_state(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);

        Livewire::actingAs($this->user('superadmin'))
            ->test(WanConfigPushLookup::class)
            ->call('selectDevice', $device->id)
            ->assertSet('selectedDeviceId', $device->id)
            ->call('clearSelection')
            ->assertSet('selectedDeviceId', null);
    }
}
