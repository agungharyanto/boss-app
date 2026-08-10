<?php

namespace Tests\Feature\Installation;

use App\Livewire\Installation\WorkOrderShow;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WorkOrderShowLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function resellerOwner(Tenant $tenant, Reseller $reseller): User
    {
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        return $owner;
    }

    public function test_non_admin_non_reseller_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(WorkOrderShow::class, ['work_order' => $workOrder])
            ->assertForbidden();
    }

    public function test_reseller_owner_can_render_and_see_devices(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        WorkOrderDevice::factory()->forWorkOrder($workOrder)->create(['serial_number' => 'SNSHOW001']);
        $owner = $this->resellerOwner($tenant, $reseller);

        Livewire::actingAs($owner)
            ->test(WorkOrderShow::class, ['work_order' => $workOrder])
            ->assertOk()
            ->assertSee('SNSHOW001');
    }

    public function test_view_only_admin_does_not_see_the_isi_wifi_button(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        WorkOrderDevice::factory()->forWorkOrder($workOrder)->create();
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'work_orders.view', 'guard_name' => 'web']));

        Livewire::actingAs($viewer)
            ->test(WorkOrderShow::class, ['work_order' => $workOrder])
            ->assertOk()
            ->assertDontSee('Isi WiFi');
    }

    public function test_saving_provisioning_form_records_credentials_and_flashes_honest_message(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $device = WorkOrderDevice::factory()->forWorkOrder($workOrder)->create();
        $owner = $this->resellerOwner($tenant, $reseller);

        Livewire::actingAs($owner)
            ->test(WorkOrderShow::class, ['work_order' => $workOrder])
            ->call('openProvisioningForm', $device->id)
            ->set('ssid', 'RumahLivewire')
            ->set('wifiPassword', 'password789')
            ->call('saveProvisioning')
            ->assertSet('provisioningDeviceId', null)
            ->assertSee('tercatat');

        $device->refresh();
        $this->assertSame('RumahLivewire', $device->ssid);
        $this->assertSame('password789', $device->wifi_password);
    }

    public function test_saving_with_neither_field_filled_shows_a_validation_error(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $device = WorkOrderDevice::factory()->forWorkOrder($workOrder)->create();
        $owner = $this->resellerOwner($tenant, $reseller);

        Livewire::actingAs($owner)
            ->test(WorkOrderShow::class, ['work_order' => $workOrder])
            ->call('openProvisioningForm', $device->id)
            ->set('ssid', '')
            ->set('wifiPassword', '')
            ->call('saveProvisioning')
            ->assertHasErrors(['ssid']);

        $this->assertNull($device->fresh()->ssid);
    }

    /**
     * Partial update via the Livewire form too — filling only SSID must not
     * wipe an already-recorded password, same guarantee as the API.
     */
    public function test_saving_ssid_only_does_not_clear_an_already_recorded_password(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $device = WorkOrderDevice::factory()->forWorkOrder($workOrder)->withWifiCredentials('OldSsid', 'oldpassword1')->create();
        $owner = $this->resellerOwner($tenant, $reseller);

        Livewire::actingAs($owner)
            ->test(WorkOrderShow::class, ['work_order' => $workOrder])
            ->call('openProvisioningForm', $device->id)
            ->set('ssid', 'NewSsid')
            ->call('saveProvisioning');

        $device->refresh();
        $this->assertSame('NewSsid', $device->ssid);
        $this->assertSame('oldpassword1', $device->wifi_password);
    }
}
