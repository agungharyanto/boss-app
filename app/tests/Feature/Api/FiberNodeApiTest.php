<?php

namespace Tests\Feature\Api;

use App\Models\FiberNode;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiberNodeApiTest extends TestCase
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

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/fiber-nodes');

        $response->assertUnauthorized();
    }

    public function test_index_forbidden_without_network_infrastructure_permission(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->nonAdminStaff($tenant))->getJson('/api/v1/fiber-nodes');

        $response->assertForbidden();
    }

    public function test_admin_can_create_an_otb_node_without_loss_values(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/fiber-nodes', [
            'node_type' => 'otb',
            'local_label' => 'OTB-Test',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.node_type', 'otb');
        $this->assertDatabaseHas('fiber_nodes', ['local_label' => 'OTB-Test', 'tenant_id' => $tenant->id]);
    }

    public function test_creating_an_odc_node_without_loss_values_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/fiber-nodes', [
            'node_type' => 'odc',
            'local_label' => 'ODC-Test',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['loss_in_db', 'loss_out_db']);
    }

    public function test_creating_an_odc_node_with_loss_values_succeeds(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/fiber-nodes', [
            'node_type' => 'odc',
            'local_label' => 'ODC-Test',
            'loss_in_db' => 1.2,
            'loss_out_db' => 1.5,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('fiber_nodes', ['local_label' => 'ODC-Test', 'loss_in_db' => 1.2, 'loss_out_db' => 1.5]);
    }

    public function test_index_returns_200_with_permission(): void
    {
        $tenant = Tenant::factory()->create();
        FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->getJson('/api/v1/fiber-nodes');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_admin_can_update_a_node(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb']);

        $response = $this->actingAs($this->admin($tenant))->putJson("/api/v1/fiber-nodes/{$node->id}", [
            'node_type' => 'otb',
            'local_label' => 'OTB-Renamed',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('fiber_nodes', ['id' => $node->id, 'local_label' => 'OTB-Renamed']);
    }

    public function test_admin_can_delete_a_node(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->deleteJson("/api/v1/fiber-nodes/{$node->id}");

        $response->assertOk();
        $this->assertSoftDeleted('fiber_nodes', ['id' => $node->id]);
    }

    public function test_non_admin_cannot_delete_a_node(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->nonAdminStaff($tenant))->deleteJson("/api/v1/fiber-nodes/{$node->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('fiber_nodes', ['id' => $node->id, 'deleted_at' => null]);
    }
}
