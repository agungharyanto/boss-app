<?php

namespace Tests\Feature\Api;

use App\Jobs\PushCustomerIpPoolToMikrotikJob;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CustomerIpPoolApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    private function nonAdminStaff(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        return $user;
    }

    private function payload(int $nasId, array $overrides = []): array
    {
        return array_merge([
            'nas_id' => $nasId,
            'name' => 'Pool Utama',
            'usage_type' => 'general',
            'network_address' => '192.168.10.0/24',
            'gateway_ip' => '192.168.10.1',
            'range_start' => '192.168.10.10',
            'range_end' => '192.168.10.200',
            'dns_primary' => '8.8.8.8',
            'dns_secondary' => '8.8.4.4',
        ], $overrides);
    }

    public function test_admin_can_create_a_customer_ip_pool(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', $this->payload($nas->id));

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Pool Utama');
        $response->assertJsonPath('data.nas_id', $nas->id);
        $response->assertJsonPath('data.usage_type', 'general');
        $this->assertDatabaseHas('customer_ip_pools', ['name' => 'Pool Utama', 'nas_id' => $nas->id, 'tenant_id' => $tenant->id]);
    }

    /**
     * v0.14.3.1 — real bug found by Agung: nothing separated a PPP-only
     * pool from a Hotspot-only pool in Grup Profil's own dropdown.
     */
    public function test_usage_type_is_required(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', $this->payload($nas->id, ['usage_type' => null]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['usage_type']);
    }

    public function test_invalid_usage_type_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', $this->payload($nas->id, ['usage_type' => 'not-a-real-type']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['usage_type']);
    }

    public function test_each_valid_usage_type_can_be_created(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        foreach (['ppp' => 101, 'hotspot' => 102, 'general' => 103] as $type => $octet) {
            $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', $this->payload($nas->id, [
                'usage_type' => $type,
                'name' => "Pool {$type}",
                'network_address' => "192.168.{$octet}.0/24",
                'gateway_ip' => "192.168.{$octet}.1",
                'range_start' => "192.168.{$octet}.10",
                'range_end' => "192.168.{$octet}.200",
            ]));

            $response->assertCreated();
            $response->assertJsonPath('data.usage_type', $type);
        }
    }

    public function test_a_role_without_customer_ip_pools_permission_cannot_list(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->nonAdminStaff($tenant))->getJson('/api/v1/customer-ip-pools');

        $response->assertForbidden();
    }

    public function test_range_start_must_be_a_valid_ip(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', $this->payload($nas->id, [
            'range_start' => 'not-an-ip',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['range_start']);
    }

    public function test_range_end_before_range_start_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', $this->payload($nas->id, [
            'range_start' => '192.168.10.200',
            'range_end' => '192.168.10.10',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['range_end']);
    }

    public function test_range_start_equal_to_range_end_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', $this->payload($nas->id, [
            'range_start' => '192.168.10.50',
            'range_end' => '192.168.10.50',
        ]));

        $response->assertCreated();
    }

    public function test_gateway_ip_outside_the_network_address_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', $this->payload($nas->id, [
            'gateway_ip' => '10.0.0.1',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['gateway_ip']);
    }

    public function test_range_outside_the_network_address_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', $this->payload($nas->id, [
            'range_start' => '10.0.0.10',
            'range_end' => '10.0.0.200',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['range_start', 'range_end']);
    }

    public function test_overlapping_range_on_the_same_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create([
            'nas_id' => $nas->id,
            'network_address' => '192.168.10.0/24',
            'gateway_ip' => '192.168.10.1',
            'range_start' => '192.168.10.10',
            'range_end' => '192.168.10.200',
        ]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', $this->payload($nas->id, [
            'name' => 'Pool Kedua',
            // Overlaps the existing pool's 10-200 range at 150-250.
            'range_start' => '192.168.10.150',
            'range_end' => '192.168.10.250',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['range_start']);
    }

    public function test_non_overlapping_range_on_the_same_nas_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create([
            'nas_id' => $nas->id,
            'network_address' => '192.168.10.0/24',
            'gateway_ip' => '192.168.10.1',
            'range_start' => '192.168.10.10',
            'range_end' => '192.168.10.100',
        ]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', $this->payload($nas->id, [
            'name' => 'Pool Kedua',
            'range_start' => '192.168.10.101',
            'range_end' => '192.168.10.200',
        ]));

        $response->assertCreated();
    }

    /**
     * Same range colliding on a DIFFERENT NAS must NOT be rejected —
     * overlap is scoped per-NAS, not globally/per-tenant.
     */
    public function test_identical_range_on_a_different_nas_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create([
            'nas_id' => $nasA->id,
            'network_address' => '192.168.10.0/24',
            'gateway_ip' => '192.168.10.1',
            'range_start' => '192.168.10.10',
            'range_end' => '192.168.10.200',
        ]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', $this->payload($nasB->id));

        $response->assertCreated();
    }

    /**
     * name is unique PER NAS — a pool named the same as one on a
     * different NAS must be allowed, per the sprint brief's explicit
     * "bukan cuma tolak semua" instruction.
     */
    public function test_same_pool_name_is_allowed_on_a_different_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create(['nas_id' => $nasA->id, 'name' => 'Pool Utama']);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', $this->payload($nasB->id, [
            'name' => 'Pool Utama',
            'network_address' => '192.168.20.0/24',
            'gateway_ip' => '192.168.20.1',
            'range_start' => '192.168.20.10',
            'range_end' => '192.168.20.200',
        ]));

        $response->assertCreated();
    }

    public function test_same_pool_name_on_the_same_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Pool Utama']);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', $this->payload($nas->id, [
            'name' => 'Pool Utama',
            'network_address' => '192.168.20.0/24',
            'gateway_ip' => '192.168.20.1',
            'range_start' => '192.168.20.10',
            'range_end' => '192.168.20.200',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_a_soft_deleted_pools_name_can_be_reused_on_the_same_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Pool Utama']);
        $pool->delete();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', $this->payload($nas->id, [
            'name' => 'Pool Utama',
        ]));

        $response->assertCreated();
    }

    public function test_admin_can_update_a_customer_ip_pool(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Lama']);

        $response = $this->actingAs($this->admin($tenant))->putJson("/api/v1/customer-ip-pools/{$pool->id}", [
            'name' => 'Baru',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Baru');
    }

    /**
     * Updating a pool does not treat its own existing range as an
     * overlap with itself.
     */
    public function test_updating_a_pool_without_changing_its_range_does_not_reject_itself_as_an_overlap(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create([
            'nas_id' => $nas->id,
            'network_address' => '192.168.10.0/24',
            'gateway_ip' => '192.168.10.1',
            'range_start' => '192.168.10.10',
            'range_end' => '192.168.10.200',
        ]);

        $response = $this->actingAs($this->admin($tenant))->putJson("/api/v1/customer-ip-pools/{$pool->id}", [
            'name' => 'Pool Utama Diperbarui',
        ]);

        $response->assertOk();
    }

    public function test_updating_a_pool_to_overlap_a_sibling_pool_on_the_same_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create([
            'nas_id' => $nas->id,
            'name' => 'Pool A',
            'network_address' => '192.168.10.0/24',
            'gateway_ip' => '192.168.10.1',
            'range_start' => '192.168.10.10',
            'range_end' => '192.168.10.100',
        ]);
        $poolB = CustomerIpPool::factory()->create([
            'nas_id' => $nas->id,
            'name' => 'Pool B',
            'network_address' => '192.168.10.0/24',
            'gateway_ip' => '192.168.10.1',
            'range_start' => '192.168.10.101',
            'range_end' => '192.168.10.200',
        ]);

        $response = $this->actingAs($this->admin($tenant))->putJson("/api/v1/customer-ip-pools/{$poolB->id}", [
            'range_start' => '192.168.10.50',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['range_start']);
    }

    public function test_admin_can_soft_delete_a_customer_ip_pool(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        $response = $this->actingAs($this->admin($tenant))->deleteJson("/api/v1/customer-ip-pools/{$pool->id}");

        $response->assertOk();
        $this->assertSoftDeleted('customer_ip_pools', ['id' => $pool->id]);
    }

    public function test_index_can_be_filtered_per_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create(['nas_id' => $nasA->id, 'name' => 'Pool A']);
        CustomerIpPool::factory()->create(['nas_id' => $nasB->id, 'name' => 'Pool B']);

        $response = $this->actingAs($this->admin($tenant))->getJson("/api/v1/customer-ip-pools?nas_id={$nasA->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Pool A');
    }

    public function test_customer_ip_pools_from_another_tenant_are_not_visible(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenantA->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenantB->id]);
        CustomerIpPool::factory()->create(['nas_id' => $nasA->id, 'name' => 'Tenant A Pool']);
        CustomerIpPool::factory()->create(['nas_id' => $nasB->id, 'name' => 'Tenant B Pool']);

        $response = $this->actingAs($this->admin($tenantA))->getJson('/api/v1/customer-ip-pools');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Tenant A Pool');
    }

    public function test_admin_can_trigger_a_manual_resync_for_a_failed_pool(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $pool->markSyncFailed('router unreachable');

        Bus::fake();

        $response = $this->actingAs($this->admin($tenant))->postJson("/api/v1/customer-ip-pools/{$pool->id}/resync");

        $response->assertOk();
        $response->assertJsonPath('data.mikrotik_sync_status', 'pending');
        Bus::assertDispatched(PushCustomerIpPoolToMikrotikJob::class, fn ($job) => $job->customerIpPoolId === $pool->id);
    }
}
