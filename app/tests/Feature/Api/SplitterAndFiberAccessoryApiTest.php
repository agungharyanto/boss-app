<?php

namespace Tests\Feature\Api;

use App\Models\FiberCable;
use App\Models\FiberNode;
use App\Models\Splitter;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SplitterAndFiberAccessoryApiTest extends TestCase
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

    public function test_splitter_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/splitters')->assertUnauthorized();
    }

    public function test_splitter_index_forbidden_without_permission(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->nonAdminStaff($tenant))->getJson('/api/v1/splitters')->assertForbidden();
    }

    public function test_admin_can_create_a_splitter(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/splitters', [
            'owner_type' => FiberNode::class,
            'owner_id' => $node->id,
            'ratio' => '1:8',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('splitters', ['owner_id' => $node->id, 'ratio' => '1:8']);
    }

    public function test_fiber_accessory_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/fiber-accessories')->assertUnauthorized();
    }

    public function test_admin_can_create_an_accessory_attached_to_a_splitter(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $splitter = Splitter::factory()->create(['owner_type' => FiberNode::class, 'owner_id' => $node->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/fiber-accessories', [
            'splitter_id' => $splitter->id,
            'accessory_type' => 'splice_fusion',
            'measured_loss_db' => 0.12,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('fiber_accessories', ['splitter_id' => $splitter->id, 'accessory_type' => 'splice_fusion']);
    }

    public function test_accessory_with_both_cable_and_splitter_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $cable = FiberCable::factory()->create(['tenant_id' => $tenant->id]);
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $splitter = Splitter::factory()->create(['owner_type' => FiberNode::class, 'owner_id' => $node->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/fiber-accessories', [
            'fiber_cable_id' => $cable->id,
            'splitter_id' => $splitter->id,
            'accessory_type' => 'connector',
        ]);

        $response->assertUnprocessable();
    }

    public function test_accessory_with_neither_cable_nor_splitter_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/fiber-accessories', [
            'accessory_type' => 'connector',
        ]);

        $response->assertUnprocessable();
    }
}
