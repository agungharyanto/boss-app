<?php

namespace Tests\Feature\Api;

use App\Enums\CommissionStatus;
use App\Enums\RegistrationChannel;
use App\Models\Agent;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationApiTest extends TestCase
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

    public function test_sales_internal_can_register_a_customer_without_an_agent(): void
    {
        $user = $this->userWithRole('sales_internal');

        $response = $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Budi Santoso',
            'address' => 'Jl. Merdeka No. 1',
            'phone_number' => '081234567890',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Budi Santoso');
        $this->assertDatabaseHas('customers', [
            'name' => 'Budi Santoso',
            'registration_channel' => RegistrationChannel::Admin->value,
        ]);
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_a_user_linked_to_an_agent_is_auto_attributed_as_the_referrer(): void
    {
        $user = $this->userWithRole('sales_freelance');
        $agent = Agent::factory()->freelance()->create(['tenant_id' => $user->tenant_id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Siti Aminah',
            'address' => 'Jl. Melati No. 2',
            'phone_number' => '081234567891',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('customers', [
            'name' => 'Siti Aminah',
            'referred_by_agent_id' => $agent->id,
            'registration_channel' => RegistrationChannel::Freelance->value,
        ]);
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $agent->id,
            'status' => CommissionStatus::Pending->value,
        ]);
    }

    public function test_a_caller_without_a_linked_agent_can_optionally_attribute_a_referral(): void
    {
        $user = $this->userWithRole('super_admin');
        $agent = Agent::factory()->create(['tenant_id' => $user->tenant_id]);

        $response = $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Andi Wijaya',
            'address' => 'Jl. Kenanga No. 3',
            'phone_number' => '081234567892',
            'referred_by_agent_id' => $agent->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $agent->id,
            'status' => CommissionStatus::Pending->value,
        ]);
    }

    public function test_role_without_register_customer_permission_is_forbidden(): void
    {
        $user = $this->userWithRole('billing');

        $response = $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Budi Santoso',
            'address' => 'Jl. Merdeka No. 1',
            'phone_number' => '081234567890',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('customers', ['name' => 'Budi Santoso']);
    }

    public function test_duplicate_nik_within_the_same_tenant_is_rejected(): void
    {
        $user = $this->userWithRole('sales_internal');

        $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Ahmad Saefulloh',
            'address' => 'Jl. Merdeka No. 1',
            'phone_number' => '081234567801',
            'nik' => '3201012501990001',
        ])->assertCreated();

        $response = $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Nama Lain',
            'address' => 'Jl. Merdeka No. 2',
            'phone_number' => '081234567802',
            'nik' => '3201012501990001',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['nik']);
    }

    public function test_same_nik_is_allowed_across_different_tenants(): void
    {
        $userA = $this->userWithRole('sales_internal');
        $userB = $this->userWithRole('sales_internal');

        $this->actingAs($userA)->postJson('/api/v1/registrations', [
            'name' => 'Pelanggan Tenant A',
            'address' => 'Jl. Merdeka No. 1',
            'phone_number' => '081234567803',
            'nik' => '3201012501990001',
        ])->assertCreated();

        $response = $this->actingAs($userB)->postJson('/api/v1/registrations', [
            'name' => 'Pelanggan Tenant B',
            'address' => 'Jl. Merdeka No. 2',
            'phone_number' => '081234567804',
            'nik' => '3201012501990001',
        ]);

        $response->assertCreated();
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $user = $this->userWithRole('sales_internal');

        $response = $this->actingAs($user)->postJson('/api/v1/registrations', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name', 'address', 'phone_number']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/v1/registrations', [])->assertUnauthorized();
    }

    public function test_an_agent_sees_their_own_referrals_with_commission_status(): void
    {
        $user = $this->userWithRole('sales_freelance');
        $agent = Agent::factory()->freelance()->create(['tenant_id' => $user->tenant_id, 'user_id' => $user->id]);

        $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Rina Kusuma',
            'address' => 'Jl. Anggrek No. 4',
            'phone_number' => '081234567893',
        ])->assertCreated();

        $response = $this->actingAs($user)->getJson('/api/v1/referrals');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.customer_name', 'Rina Kusuma');
        $response->assertJsonPath('data.0.commission_status', CommissionStatus::Pending->value);
    }

    public function test_a_caller_with_permission_but_no_linked_agent_gets_not_found(): void
    {
        $user = $this->userWithRole('sales_internal');

        $this->actingAs($user)->getJson('/api/v1/referrals')->assertNotFound();
    }

    public function test_role_without_register_customer_permission_cannot_view_referrals(): void
    {
        $user = $this->userWithRole('billing');

        $this->actingAs($user)->getJson('/api/v1/referrals')->assertForbidden();
    }
}
