<?php

namespace Tests\Feature\Api;

use App\Models\FiberNode;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiberCableApiTest extends TestCase
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

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/fiber-cables');

        $response->assertUnauthorized();
    }

    public function test_creating_a_cable_with_an_odd_total_cores_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $from = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $to = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/fiber-cables', [
            'from_type' => FiberNode::class,
            'from_id' => $from->id,
            'to_type' => FiberNode::class,
            'to_id' => $to->id,
            'total_cores' => 5,
            'tube_count' => 1,
            'cores_per_tube' => 5,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['total_cores']);
        $this->assertDatabaseCount('fiber_cables', 0);
    }

    public function test_creating_a_cable_with_valid_even_cores_succeeds_and_generates_fiber_core_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $from = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $to = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/fiber-cables', [
            'from_type' => FiberNode::class,
            'from_id' => $from->id,
            'to_type' => FiberNode::class,
            'to_id' => $to->id,
            'total_cores' => 12,
            'tube_count' => 2,
            'cores_per_tube' => 6,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.cores_count', 12);
        $this->assertDatabaseCount('fiber_cores', 12);
    }

    public function test_cable_with_the_same_endpoint_on_both_sides_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/fiber-cables', [
            'from_type' => FiberNode::class,
            'from_id' => $node->id,
            'to_type' => FiberNode::class,
            'to_id' => $node->id,
            'total_cores' => 4,
            'tube_count' => 1,
            'cores_per_tube' => 4,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['to_id']);
    }
}
