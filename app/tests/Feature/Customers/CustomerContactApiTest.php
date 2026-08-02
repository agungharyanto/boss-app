<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerContactApiTest extends TestCase
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

    public function test_customer_service_can_add_contact(): void
    {
        $user = $this->userWithRole('customer_service');
        $customer = Customer::factory()->create(['tenant_id' => $user->tenant_id]);

        $response = $this->actingAs($user)->postJson("/api/v1/customers/{$customer->id}/contacts", [
            'name' => 'Siti Aminah',
            'phone_number' => '081298765432',
            'relationship' => 'Istri',
            'access_level' => 'full',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('customer_contacts', [
            'customer_id' => $customer->id,
            'name' => 'Siti Aminah',
        ]);
    }

    public function test_view_only_role_cannot_add_contact(): void
    {
        $user = $this->userWithRole('sales_internal');
        $customer = Customer::factory()->create(['tenant_id' => $user->tenant_id]);

        $this->actingAs($user)->postJson("/api/v1/customers/{$customer->id}/contacts", [
            'name' => 'Siti Aminah',
            'phone_number' => '081298765432',
            'access_level' => 'full',
        ])->assertForbidden();
    }

    public function test_marking_a_new_contact_as_authorized_unmarks_the_previous_one(): void
    {
        $user = $this->userWithRole('customer_service');
        $customer = Customer::factory()->create(['tenant_id' => $user->tenant_id]);
        $first = CustomerContact::factory()->authorized()->create(['customer_id' => $customer->id]);

        $response = $this->actingAs($user)->postJson("/api/v1/customers/{$customer->id}/contacts", [
            'name' => 'Joko',
            'phone_number' => '081211112222',
            'access_level' => 'emergency',
            'is_authorized_contact' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.is_authorized_contact', true);

        $this->assertDatabaseHas('customer_contacts', ['id' => $first->id, 'is_authorized_contact' => false]);
        $this->assertDatabaseHas('customer_contacts', ['name' => 'Joko', 'is_authorized_contact' => true]);

        $this->assertSame(
            1,
            CustomerContact::where('customer_id', $customer->id)->where('is_authorized_contact', true)->count()
        );
    }

    public function test_switching_authorized_contact_via_update_also_enforces_single_invariant(): void
    {
        $user = $this->userWithRole('customer_service');
        $customer = Customer::factory()->create(['tenant_id' => $user->tenant_id]);
        $first = CustomerContact::factory()->authorized()->create(['customer_id' => $customer->id]);
        $second = CustomerContact::factory()->create(['customer_id' => $customer->id, 'is_authorized_contact' => false]);

        $this->actingAs($user)
            ->putJson("/api/v1/customers/{$customer->id}/contacts/{$second->id}", ['is_authorized_contact' => true])
            ->assertOk();

        $this->assertDatabaseHas('customer_contacts', ['id' => $first->id, 'is_authorized_contact' => false]);
        $this->assertDatabaseHas('customer_contacts', ['id' => $second->id, 'is_authorized_contact' => true]);
    }

    public function test_contact_from_another_customer_is_not_accessible_via_wrong_customer_id(): void
    {
        $user = $this->userWithRole('customer_service');
        $customerA = Customer::factory()->create(['tenant_id' => $user->tenant_id]);
        $customerB = Customer::factory()->create(['tenant_id' => $user->tenant_id]);
        $contact = CustomerContact::factory()->create(['customer_id' => $customerB->id]);

        $this->actingAs($user)
            ->getJson("/api/v1/customers/{$customerA->id}/contacts/{$contact->id}")
            ->assertNotFound();
    }

    public function test_customer_service_can_delete_contact(): void
    {
        $user = $this->userWithRole('customer_service');
        $customer = Customer::factory()->create(['tenant_id' => $user->tenant_id]);
        $contact = CustomerContact::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/customers/{$customer->id}/contacts/{$contact->id}")
            ->assertOk();

        $this->assertDatabaseMissing('customer_contacts', ['id' => $contact->id]);
    }
}
