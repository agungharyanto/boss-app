<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_customer_service_can_create_customer(): void
    {
        $user = $this->userWithRole('customer_service');

        $response = $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Budi Santoso',
            'address' => 'Jl. Merdeka No. 1',
            'phone_number' => '081234567890',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'prospek');
        $this->assertDatabaseHas('customers', ['name' => 'Budi Santoso', 'status' => 'prospek']);
    }

    public function test_view_only_role_cannot_create_customer(): void
    {
        $user = $this->userWithRole('noc');

        $response = $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Budi Santoso',
            'address' => 'Jl. Merdeka No. 1',
            'phone_number' => '081234567890',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('customers', ['name' => 'Budi Santoso']);
    }

    public function test_view_only_role_can_list_and_view_customers(): void
    {
        $user = $this->userWithRole('billing');
        $customer = Customer::factory()->create(['tenant_id' => $user->tenant_id]);

        $this->actingAs($user)->getJson('/api/v1/customers')->assertOk();
        $this->actingAs($user)->getJson("/api/v1/customers/{$customer->id}")->assertOk();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
    }

    public function test_customer_service_can_update_customer_profile(): void
    {
        $user = $this->userWithRole('customer_service');
        $customer = Customer::factory()->create(['tenant_id' => $user->tenant_id, 'name' => 'Old Name']);

        $response = $this->actingAs($user)->putJson("/api/v1/customers/{$customer->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'New Name']);
    }

    public function test_view_only_role_cannot_update_customer(): void
    {
        $user = $this->userWithRole('teknisi');
        $customer = Customer::factory()->create(['tenant_id' => $user->tenant_id]);

        $this->actingAs($user)
            ->putJson("/api/v1/customers/{$customer->id}", ['name' => 'Hacked'])
            ->assertForbidden();
    }
}
