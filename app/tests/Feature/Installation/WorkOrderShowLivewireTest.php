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

    // --- v0.26.1 — "Jadwalkan Kunjungan" ---

    public function test_view_only_admin_does_not_see_the_atur_jadwal_button(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'work_orders.view', 'guard_name' => 'web']));

        Livewire::actingAs($viewer)
            ->test(WorkOrderShow::class, ['work_order' => $workOrder])
            ->assertOk()
            ->assertDontSee('Atur Jadwal');
    }

    public function test_work_order_without_a_schedule_shows_the_belum_ada_janji_message(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id, 'scheduled_at' => null]);
        $owner = $this->resellerOwner($tenant, $reseller);

        Livewire::actingAs($owner)
            ->test(WorkOrderShow::class, ['work_order' => $workOrder])
            ->assertSee('Belum ada janji spesifik')
            ->assertSee('Atur Jadwal');
    }

    public function test_saving_a_schedule_persists_it_and_flashes_a_message(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id, 'scheduled_at' => null]);
        $owner = $this->resellerOwner($tenant, $reseller);

        Livewire::actingAs($owner)
            ->test(WorkOrderShow::class, ['work_order' => $workOrder])
            ->call('startEditingSchedule')
            ->set('scheduledAtInput', '2026-10-05T09:00')
            ->call('saveSchedule')
            ->assertSet('editingSchedule', false)
            ->assertSee('tersimpan');

        $this->assertSame('2026-10-05 09:00:00', $workOrder->fresh()->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_saving_an_empty_schedule_clears_an_existing_one(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => $reseller->id,
            'scheduled_at' => '2026-10-01 10:00:00',
        ]);
        $owner = $this->resellerOwner($tenant, $reseller);

        Livewire::actingAs($owner)
            ->test(WorkOrderShow::class, ['work_order' => $workOrder])
            ->call('startEditingSchedule')
            ->assertSet('scheduledAtInput', '2026-10-01T10:00')
            ->set('scheduledAtInput', '')
            ->call('saveSchedule');

        $this->assertNull($workOrder->fresh()->scheduled_at);
    }

    public function test_an_invalid_schedule_value_shows_a_validation_error(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id, 'scheduled_at' => null]);
        $owner = $this->resellerOwner($tenant, $reseller);

        Livewire::actingAs($owner)
            ->test(WorkOrderShow::class, ['work_order' => $workOrder])
            ->call('startEditingSchedule')
            ->set('scheduledAtInput', 'bukan-tanggal')
            ->call('saveSchedule')
            ->assertHasErrors(['scheduledAtInput']);

        $this->assertNull($workOrder->fresh()->scheduled_at);
    }
}
