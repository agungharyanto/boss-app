<?php

namespace Tests\Feature\Tenancy;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerTimelineEntry;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userFor(Tenant $tenant, string $role = 'customer_service'): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    public function test_plain_eloquent_query_excludes_other_tenants_customers_without_manual_where(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userFor($tenantA);

        Customer::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Customer A']);
        Customer::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Customer B']);

        $this->actingAs($userA);

        // No ->where('tenant_id', ...) anywhere here — the global scope must do this automatically.
        $this->assertSame(1, Customer::count());
        $this->assertSame('Customer A', Customer::first()->name);
        $this->assertNull(Customer::where('name', 'Customer B')->first());
    }

    public function test_api_index_only_returns_current_tenants_customers(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userFor($tenantA);

        Customer::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Customer A']);
        Customer::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Customer B']);

        $response = $this->actingAs($userA)->getJson('/api/v1/customers');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Customer A'));
        $this->assertFalse($names->contains('Customer B'));
    }

    public function test_api_show_404s_for_another_tenants_customer_via_route_model_binding(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userFor($tenantA);

        $customerB = Customer::factory()->create(['tenant_id' => $tenantB->id]);

        // Route model binding resolves Customer via the same scoped query builder, so a
        // customer belonging to a different tenant is invisible — 404, not 403.
        $this->actingAs($userA)
            ->getJson("/api/v1/customers/{$customerB->id}")
            ->assertNotFound();
    }

    public function test_api_update_404s_for_another_tenants_customer(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userFor($tenantA);

        $customerB = Customer::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)
            ->putJson("/api/v1/customers/{$customerB->id}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertDatabaseMissing('customers', ['id' => $customerB->id, 'name' => 'Hijacked']);
    }

    public function test_new_customer_automatically_gets_the_acting_users_tenant_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userFor($tenant);

        $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Auto Tenant Customer',
            'address' => 'Jl. Contoh',
            'phone_number' => '081200000000',
        ])->assertCreated();

        $this->assertDatabaseHas('customers', [
            'name' => 'Auto Tenant Customer',
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_customer_contacts_are_also_isolated_per_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userFor($tenantA);

        $customerB = Customer::factory()->create(['tenant_id' => $tenantB->id]);
        $contactB = CustomerContact::factory()->create(['customer_id' => $customerB->id]);

        $this->actingAs($userA);

        $this->assertSame(0, CustomerContact::count());
        $this->assertNull(CustomerContact::find($contactB->id));
    }

    public function test_timeline_entries_are_also_isolated_per_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userFor($tenantA);

        // Creating this customer (as an unauthenticated arrange step) also fires the
        // CustomerObserver, which writes a customer_created timeline entry for tenant B.
        Customer::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA);

        $this->assertSame(0, CustomerTimelineEntry::count());
    }
}
