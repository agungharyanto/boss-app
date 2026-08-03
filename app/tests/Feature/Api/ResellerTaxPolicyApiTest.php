<?php

namespace Tests\Feature\Api;

use App\Models\Reseller;
use App\Models\TaxComponent;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerTaxPolicyApiTest extends TestCase
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
        $user->assignRole('super_admin');

        return $user;
    }

    private function resellerOwner(Reseller $reseller): User
    {
        $user = User::factory()->create(['tenant_id' => $reseller->tenant_id]);
        $reseller->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);

        return $user;
    }

    private function resellerStaff(Reseller $reseller): User
    {
        $user = User::factory()->create(['tenant_id' => $reseller->tenant_id]);
        $reseller->users()->attach($user->id, ['role' => 'staff', 'status' => 'active']);

        return $user;
    }

    public function test_admin_can_set_direct_retail_policy(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $ppn = TaxComponent::factory()->for($tenant)->create();

        $response = $this->actingAs($admin)->postJson('/api/v1/reseller-tax-policies', [
            'reseller_id' => null,
            'tax_component_id' => $ppn->id,
            'burden' => 'customer_borne',
            'effective_from' => now()->startOfMonth()->toDateString(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.reseller_id', null);
        $response->assertJsonPath('data.burden', 'customer_borne');
    }

    public function test_reseller_owner_can_set_policy_for_own_reseller(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->for($tenant)->create();
        $owner = $this->resellerOwner($reseller);
        $ppn = TaxComponent::factory()->for($tenant)->create();

        $response = $this->actingAs($owner)->postJson('/api/v1/reseller-tax-policies', [
            'reseller_id' => $reseller->id,
            'tax_component_id' => $ppn->id,
            'burden' => 'split',
            'split_ratio' => 40,
            'effective_from' => now()->startOfMonth()->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertEquals(40, $response->json('data.split_ratio'));
    }

    public function test_reseller_owner_cannot_set_policy_for_another_reseller(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->for($tenant)->create();
        $resellerB = Reseller::factory()->for($tenant)->create();
        $ownerA = $this->resellerOwner($resellerA);
        $ppn = TaxComponent::factory()->for($tenant)->create();

        $this->actingAs($ownerA)->postJson('/api/v1/reseller-tax-policies', [
            'reseller_id' => $resellerB->id,
            'tax_component_id' => $ppn->id,
            'burden' => 'customer_borne',
            'effective_from' => now()->startOfMonth()->toDateString(),
        ])->assertForbidden();
    }

    public function test_reseller_owner_cannot_set_direct_retail_policy(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->for($tenant)->create();
        $owner = $this->resellerOwner($reseller);
        $ppn = TaxComponent::factory()->for($tenant)->create();

        $this->actingAs($owner)->postJson('/api/v1/reseller-tax-policies', [
            'reseller_id' => null,
            'tax_component_id' => $ppn->id,
            'burden' => 'customer_borne',
            'effective_from' => now()->startOfMonth()->toDateString(),
        ])->assertForbidden();
    }

    public function test_reseller_staff_cannot_create_policy_but_can_view(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->for($tenant)->create();
        $staff = $this->resellerStaff($reseller);
        $ppn = TaxComponent::factory()->for($tenant)->create();

        $this->actingAs($staff)->postJson('/api/v1/reseller-tax-policies', [
            'reseller_id' => $reseller->id,
            'tax_component_id' => $ppn->id,
            'burden' => 'customer_borne',
            'effective_from' => now()->startOfMonth()->toDateString(),
        ])->assertForbidden();

        $this->actingAs($staff)->getJson('/api/v1/reseller-tax-policies')->assertOk();
    }

    public function test_split_without_ratio_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $ppn = TaxComponent::factory()->for($tenant)->create();

        $this->actingAs($admin)->postJson('/api/v1/reseller-tax-policies', [
            'reseller_id' => null,
            'tax_component_id' => $ppn->id,
            'burden' => 'split',
            'effective_from' => now()->startOfMonth()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['split_ratio']);
    }

    public function test_reseller_index_only_shows_own_resellers_policies(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->for($tenant)->create();
        $resellerB = Reseller::factory()->for($tenant)->create();
        $ownerA = $this->resellerOwner($resellerA);
        $ppn = TaxComponent::factory()->for($tenant)->create();

        $this->actingAs($this->admin($tenant))->postJson('/api/v1/reseller-tax-policies', [
            'reseller_id' => $resellerA->id, 'tax_component_id' => $ppn->id,
            'burden' => 'customer_borne', 'effective_from' => now()->startOfMonth()->toDateString(),
        ])->assertCreated();

        $this->actingAs($this->admin($tenant))->postJson('/api/v1/reseller-tax-policies', [
            'reseller_id' => $resellerB->id, 'tax_component_id' => $ppn->id,
            'burden' => 'reseller_borne', 'effective_from' => now()->startOfMonth()->toDateString(),
        ])->assertCreated();

        $response = $this->actingAs($ownerA)->getJson('/api/v1/reseller-tax-policies');
        $ids = collect($response->json('data'))->pluck('reseller_id');

        $this->assertTrue($ids->every(fn ($id) => $id === $resellerA->id));
    }
}
