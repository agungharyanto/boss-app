<?php

namespace Tests\Feature\Installation;

use App\Enums\WorkOrderStatus;
use App\Livewire\Installation\WorkOrderIndex;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * v0.26.2b — daftar Work Order (bukan CRUD). Visibility mengikuti
 * `WorkOrderPolicy` PERSIS (viewAny/scopeForTechnician) — tidak ada
 * definisi visibility baru yang diuji terpisah di sini, cukup dipastikan
 * komponen ini genuinely memanggil jalur yang sama.
 */
class WorkOrderIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant, string $role = 'superadmin'): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function workOrder(Tenant $tenant, WorkOrderStatus $status = WorkOrderStatus::PendingVerification, array $overrides = []): WorkOrder
    {
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'reseller_id' => null,
        ]);

        return WorkOrder::factory()->forSubscription($subscription)->create(array_merge([
            'status' => $status,
        ], $overrides));
    }

    public function test_a_user_with_no_access_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(WorkOrderIndex::class)
            ->assertForbidden();
    }

    public function test_admin_sees_work_orders_from_their_own_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderIndex::class)
            ->assertOk()
            ->assertSee('#'.$wo->id);
    }

    public function test_work_order_from_another_tenant_never_appears(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $ownWo = $this->workOrder($tenantA);
        $otherWo = $this->workOrder($tenantB);

        Livewire::actingAs($this->admin($tenantA))
            ->test(WorkOrderIndex::class)
            ->assertOk()
            ->assertSee('#'.$ownWo->id)
            ->assertDontSee('#'.$otherWo->id);
    }

    public function test_status_filter_narrows_the_list(): void
    {
        $tenant = Tenant::factory()->create();
        $ready = $this->workOrder($tenant, WorkOrderStatus::Ready, ['equipment_ready' => true]);
        $pending = $this->workOrder($tenant, WorkOrderStatus::PendingVerification);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderIndex::class)
            ->set('statusFilter', WorkOrderStatus::Ready->value)
            ->assertSee('#'.$ready->id)
            ->assertDontSee('#'.$pending->id);
    }

    public function test_assigning_a_technician_to_a_ready_work_order_transitions_it_to_assigned(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant, WorkOrderStatus::Ready, ['equipment_ready' => true]);
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderIndex::class)
            ->call('assignTechnician', $wo->id, (string) $technician->id)
            ->assertHasNoErrors();

        $wo->refresh();
        $this->assertSame($technician->id, $wo->technician_id);
        $this->assertSame(WorkOrderStatus::Assigned, $wo->status);
    }

    public function test_assigning_a_technician_to_a_non_ready_work_order_is_rejected_without_changing_it(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant, WorkOrderStatus::PendingVerification);
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderIndex::class)
            ->call('assignTechnician', $wo->id, (string) $technician->id)
            ->assertHasErrors('assign');

        $wo->refresh();
        $this->assertNull($wo->technician_id);
        $this->assertSame(WorkOrderStatus::PendingVerification, $wo->status);
    }

    public function test_choosing_belum_ditugaskan_is_a_noop(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant, WorkOrderStatus::Ready, ['equipment_ready' => true]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderIndex::class)
            ->call('assignTechnician', $wo->id, '')
            ->assertHasNoErrors();

        $wo->refresh();
        $this->assertNull($wo->technician_id);
        $this->assertSame(WorkOrderStatus::Ready, $wo->status);
    }

    public function test_assigning_is_forbidden_for_a_user_without_manage_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant, WorkOrderStatus::Ready, ['equipment_ready' => true]);
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id]);

        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'work_orders.view', 'guard_name' => 'web']));

        Livewire::actingAs($viewer)
            ->test(WorkOrderIndex::class)
            ->call('assignTechnician', $wo->id, (string) $technician->id)
            ->assertForbidden();

        $this->assertNull($wo->fresh()->technician_id);
    }

    public function test_technician_dropdown_only_lists_active_technicians(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant, WorkOrderStatus::Ready, ['equipment_ready' => true]);
        $active = Technician::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Teknisi Aktif']);
        $inactive = Technician::factory()->inactive()->create(['tenant_id' => $tenant->id, 'name' => 'Teknisi Nonaktif']);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderIndex::class)
            ->assertSee('Teknisi Aktif')
            ->assertDontSee('Teknisi Nonaktif');

        // Silence unused-variable warnings from static analysis while
        // keeping both factory rows genuinely created above.
        $this->assertNotSame($active->id, $inactive->id);
    }
}
