<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTimelineApiTest extends TestCase
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

    public function test_creating_a_customer_is_recorded_in_its_timeline(): void
    {
        $user = $this->userWithRole('customer_service');

        $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Budi Santoso',
            'address' => 'Jl. Merdeka No. 1',
            'phone_number' => '081234567890',
        ])->assertCreated();

        $customer = Customer::firstWhere('name', 'Budi Santoso');

        $this->assertDatabaseHas('customer_timeline_entries', [
            'customer_id' => $customer->id,
            'event_type' => 'customer_created',
        ]);
    }

    public function test_view_only_role_can_read_timeline(): void
    {
        $user = $this->userWithRole('finance');
        $customer = Customer::factory()->create(['tenant_id' => $user->tenant_id]);

        $this->actingAs($user)
            ->getJson("/api/v1/customers/{$customer->id}/timeline")
            ->assertOk();
    }

    public function test_timeline_endpoint_is_read_only(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/customers/{customer}/timeline'));

        $this->assertTrue($routes->every(fn ($route) => in_array('GET', $route->methods())));
    }

    public function test_profile_update_is_recorded_with_before_and_after_values(): void
    {
        $user = $this->userWithRole('customer_service');
        $customer = Customer::factory()->create(['tenant_id' => $user->tenant_id, 'name' => 'Nama Lama']);

        $this->actingAs($user)->putJson("/api/v1/customers/{$customer->id}", [
            'name' => 'Nama Baru',
        ])->assertOk();

        $entry = $customer->timelineEntries()->where('event_type', 'profile_updated')->first();

        $this->assertNotNull($entry);
        $this->assertSame('Nama Lama', $entry->changes['name']['from']);
        $this->assertSame('Nama Baru', $entry->changes['name']['to']);
    }
}
