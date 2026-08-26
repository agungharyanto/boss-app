<?php

namespace Tests\Feature\Resellers;

use App\Models\Customer;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ResellerContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerScopeIsolationTest extends TestCase
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

    private function resellerOwner(Reseller $reseller): User
    {
        $user = User::factory()->create(['tenant_id' => $reseller->tenant_id]);
        $reseller->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);

        return $user;
    }

    public function test_reseller_scope_filters_a_plain_query_only_when_context_is_set(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->for($tenant)->create();
        $resellerB = Reseller::factory()->for($tenant)->create();

        Customer::factory()->for($tenant)->create(['reseller_id' => $resellerA->id, 'name' => 'Customer A']);
        Customer::factory()->for($tenant)->create(['reseller_id' => $resellerB->id, 'name' => 'Customer B']);
        Customer::factory()->for($tenant)->create(['reseller_id' => null, 'name' => 'Customer Direct']);

        $admin = $this->admin($tenant);
        $this->actingAs($admin);

        // No context resolved (default) — sees every row regardless of reseller.
        $this->assertSame(3, Customer::count());

        // Manually set context (this is what ResolveResellerContext does per
        // request) to prove ResellerScope narrows the query when active.
        app(ResellerContext::class)->set($resellerA);

        $this->assertSame(1, Customer::count());
        $this->assertSame('Customer A', Customer::first()->name);
    }

    public function test_api_customers_index_only_returns_own_resellers_customers_for_reseller_user(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->for($tenant)->create();
        $resellerB = Reseller::factory()->for($tenant)->create();
        $ownerA = $this->resellerOwner($resellerA);

        Customer::factory()->for($tenant)->create(['reseller_id' => $resellerA->id, 'name' => 'Customer A']);
        Customer::factory()->for($tenant)->create(['reseller_id' => $resellerB->id, 'name' => 'Customer B']);
        Customer::factory()->for($tenant)->create(['reseller_id' => null, 'name' => 'Customer Direct']);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/customers');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Customer A'));
        $this->assertFalse($names->contains('Customer B'));
        $this->assertFalse($names->contains('Customer Direct'));
    }

    public function test_api_customers_show_404s_for_another_resellers_customer(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->for($tenant)->create();
        $resellerB = Reseller::factory()->for($tenant)->create();
        $ownerA = $this->resellerOwner($resellerA);

        $customerB = Customer::factory()->for($tenant)->create(['reseller_id' => $resellerB->id]);

        $this->actingAs($ownerA)
            ->getJson("/api/v1/customers/{$customerB->id}")
            ->assertNotFound();
    }

    public function test_admin_without_reseller_context_sees_direct_and_all_reseller_customers(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->for($tenant)->create();
        $resellerB = Reseller::factory()->for($tenant)->create();
        $admin = $this->admin($tenant);

        Customer::factory()->for($tenant)->create(['reseller_id' => $resellerA->id, 'name' => 'Customer A']);
        Customer::factory()->for($tenant)->create(['reseller_id' => $resellerB->id, 'name' => 'Customer B']);
        Customer::factory()->for($tenant)->create(['reseller_id' => null, 'name' => 'Customer Direct']);

        $response = $this->actingAs($admin)->getJson('/api/v1/customers');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Customer A'));
        $this->assertTrue($names->contains('Customer B'));
        $this->assertTrue($names->contains('Customer Direct'));
    }

    public function test_new_customer_created_by_reseller_owner_automatically_gets_their_reseller_id(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->for($tenant)->create();
        $owner = $this->resellerOwner($reseller);

        $response = $this->actingAs($owner)->postJson('/api/v1/customers', [
            'name' => 'New Customer',
            'address' => 'Jl. Testing No.1',
            'phone_number' => '081234567890',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.reseller_id', $reseller->id);
        $this->assertDatabaseHas('customers', ['name' => 'New Customer', 'reseller_id' => $reseller->id]);
    }

    public function test_admin_can_explicitly_assign_reseller_id_when_creating_a_customer(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $reseller = Reseller::factory()->for($tenant)->create();

        $response = $this->actingAs($admin)->postJson('/api/v1/customers', [
            'name' => 'Admin Assigned Customer',
            'address' => 'Jl. Testing No.2',
            'phone_number' => '081234567891',
            'reseller_id' => $reseller->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.reseller_id', $reseller->id);
    }

    public function test_admin_created_customer_with_no_reseller_id_stays_a_direct_customer(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        $response = $this->actingAs($admin)->postJson('/api/v1/customers', [
            'name' => 'Direct Customer',
            'address' => 'Jl. Testing No.3',
            'phone_number' => '081234567892',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.reseller_id', null);
    }
}
