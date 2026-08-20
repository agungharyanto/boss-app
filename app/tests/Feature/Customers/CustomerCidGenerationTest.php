<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCidGenerationTest extends TestCase
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

    public function test_cid_is_automatically_assigned_on_customer_creation(): void
    {
        $customer = Customer::factory()->create();

        $this->assertNotNull($customer->fresh()->cid);
    }

    public function test_cid_cannot_be_injected_via_the_create_api_and_is_generated_instead(): void
    {
        $user = $this->userWithRole('customer_service');

        $response = $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Budi Santoso',
            'address' => 'Jl. Merdeka No. 1',
            'phone_number' => '081234567890',
            'cid' => 'HACKED-000001',
        ]);

        $response->assertCreated();

        // Direct customer (no reseller) — random "{YYYY}{MM}{4 digit}" cid,
        // not the injected value.
        $cid = $response->json('data.cid');
        $this->assertMatchesRegularExpression('/^\d{10}$/', $cid);
        $this->assertStringStartsWith(now()->format('Ym'), $cid);

        $this->assertDatabaseHas('customers', ['name' => 'Budi Santoso', 'cid' => $cid]);
        $this->assertDatabaseMissing('customers', ['cid' => 'HACKED-000001']);
    }

    public function test_cid_cannot_be_changed_via_the_update_api(): void
    {
        $user = $this->userWithRole('customer_service');
        $customer = Customer::factory()->create(['tenant_id' => $user->tenant_id]);
        $originalCid = $customer->fresh()->cid;

        $response = $this->actingAs($user)->putJson("/api/v1/customers/{$customer->id}", [
            'name' => 'Nama Baru',
            'cid' => 'TAMPERED-000099',
        ]);

        $response->assertOk();
        $this->assertSame($originalCid, $customer->fresh()->cid);
        $this->assertDatabaseMissing('customers', ['cid' => 'TAMPERED-000099']);
    }

    public function test_cid_is_exposed_in_the_api_resource(): void
    {
        $user = $this->userWithRole('customer_service');
        $customer = Customer::factory()->create(['tenant_id' => $user->tenant_id]);

        $response = $this->actingAs($user)->getJson("/api/v1/customers/{$customer->id}");

        $response->assertOk();
        $response->assertJsonPath('data.cid', $customer->fresh()->cid);
    }
}
