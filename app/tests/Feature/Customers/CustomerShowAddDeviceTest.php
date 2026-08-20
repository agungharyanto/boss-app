<?php

namespace Tests\Feature\Customers;

use App\Livewire\Customers\CustomerShow;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * "Tambah Device CPE" on a customer's own detail page (v0.7.6-follow-up) —
 * the first manual bind path that doesn't need a WorkOrder, for a customer
 * like Sartimin who has zero cpe_devices rows and never went through
 * Installation. Reuses CpeBindingService::bindFromLegacyImport() as-is
 * (same method "Ganti Modem" on /cpe-devices already calls) — this file
 * only tests the NEW UI/authorization wiring, not that method's own
 * binding logic (already covered by CpeDeviceActionControllerTest and
 * others).
 */
class CustomerShowAddDeviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_button_appears_for_admin_when_customer_has_no_device(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $customer])
            ->assertSee('Tambah Device CPE')
            ->assertSee('belum punya device CPE ter-bind');
    }

    public function test_button_is_hidden_when_customer_already_has_a_device(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'serial_number' => 'SNEXISTING001']);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $customer])
            ->assertDontSee('Tambah Device CPE')
            ->assertSee('SNEXISTING001')
            ->assertSee('Ganti Modem');
    }

    public function test_button_is_hidden_for_a_user_with_no_cpe_permission_and_no_reseller_membership(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'customers.view', 'guard_name' => 'web']));

        Livewire::actingAs($viewer)
            ->test(CustomerShow::class, ['customer' => $customer])
            ->assertDontSee('Tambah Device CPE');
    }

    public function test_a_resellers_own_owner_can_add_a_device_for_their_own_customer(): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices*' => Http::response([[
                '_id' => 'F86CE1-F663NV3a-ZICGNEWONE01',
                '_deviceId' => ['_OUI' => 'F86CE1', '_ProductClass' => 'F663NV3a', '_SerialNumber' => 'ZICGNEWONE01'],
                '_lastInform' => now()->toIso8601String(),
            ]], 200),
        ]);
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        Livewire::actingAs($owner)
            ->test(CustomerShow::class, ['customer' => $customer])
            ->assertSee('Tambah Device CPE')
            ->call('openAddDeviceForm')
            ->set('newDeviceSerial', 'ZICGNEWONE01')
            ->call('bindDevice')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('cpe_devices', [
            'customer_id' => $customer->id,
            'serial_number' => 'ZICGNEWONE01',
            'status' => 'online',
        ]);
    }

    public function test_binding_a_serial_known_to_genieacs_succeeds_and_shows_online_status(): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices*' => Http::response([[
                '_id' => 'F86CE1-F663NV3a-ZTEGCB399CEB',
                '_deviceId' => ['_OUI' => 'F86CE1', '_ProductClass' => 'F663NV3a', '_SerialNumber' => 'ZTEGCB399CEB'],
                '_lastInform' => now()->toIso8601String(),
            ]], 200),
        ]);
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sartimin']);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $customer])
            ->call('openAddDeviceForm')
            ->set('newDeviceSerial', 'ZTEGCB399CEB')
            ->call('bindDevice');

        $component->assertHasNoErrors()
            ->assertSet('showAddDeviceForm', false)
            ->assertSee('sudah dikenali GenieACS');

        $this->assertDatabaseHas('cpe_devices', [
            'customer_id' => $customer->id,
            'serial_number' => 'ZTEGCB399CEB',
            'genieacs_device_id' => 'F86CE1-F663NV3a-ZTEGCB399CEB',
            'status' => 'online',
        ]);
    }

    public function test_serial_not_found_in_genieacs_still_binds_gracefully_not_a_crash(): void
    {
        Http::fake(['genieacs-nbi:7557/devices*' => Http::response([], 200)]);
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $customer])
            ->call('openAddDeviceForm')
            ->set('newDeviceSerial', 'NEVERSEENBYGENIEACS')
            ->call('bindDevice');

        $component->assertHasNoErrors()
            ->assertSee('belum pernah terlihat di GenieACS');

        $this->assertDatabaseHas('cpe_devices', [
            'customer_id' => $customer->id,
            'serial_number' => 'NEVERSEENBYGENIEACS',
            'genieacs_device_id' => null,
            'status' => 'pending_first_connect',
        ]);
    }

    public function test_empty_serial_shows_a_validation_error(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $customer])
            ->call('openAddDeviceForm')
            ->set('newDeviceSerial', '')
            ->call('bindDevice')
            ->assertHasErrors(['newDeviceSerial']);

        $this->assertDatabaseMissing('cpe_devices', ['customer_id' => $customer->id]);
    }

    public function test_a_user_with_no_permission_cannot_call_bind_device_directly(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'customers.view', 'guard_name' => 'web']));

        Livewire::actingAs($viewer)
            ->test(CustomerShow::class, ['customer' => $customer])
            ->set('newDeviceSerial', 'SNSHOULDNOTBIND')
            ->call('bindDevice')
            ->assertForbidden();

        $this->assertDatabaseMissing('cpe_devices', ['customer_id' => $customer->id]);
    }

    public function test_cannot_bind_a_second_device_once_one_already_exists(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'serial_number' => 'SNFIRST001']);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $customer])
            ->set('newDeviceSerial', 'SNSECOND002')
            ->call('bindDevice')
            ->assertHasErrors(['newDeviceSerial']);

        $this->assertDatabaseMissing('cpe_devices', ['serial_number' => 'SNSECOND002']);
    }
}
