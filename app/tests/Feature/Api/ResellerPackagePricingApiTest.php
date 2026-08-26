<?php

namespace Tests\Feature\Api;

use App\Models\Reseller;
use App\Models\ResellerPackagePricing;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerPackagePricingApiTest extends TestCase
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

    public function test_reseller_owner_creates_package_pricing_auto_attributed_to_own_reseller(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->for($tenant)->create();
        $owner = $this->resellerOwner($reseller);

        // No reseller_id sent at all — must come from resolved context.
        $response = $this->actingAs($owner)->postJson('/api/v1/reseller-package-pricing', [
            'name' => 'Paket 20 Mbps',
            'price' => 150000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.reseller_id', $reseller->id);
        $this->assertDatabaseHas('reseller_package_pricing', [
            'name' => 'Paket 20 Mbps',
            'reseller_id' => $reseller->id,
        ]);
    }

    public function test_reseller_owner_can_create_custom_bundle_package(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->for($tenant)->create();
        $owner = $this->resellerOwner($reseller);

        $response = $this->actingAs($owner)->postJson('/api/v1/reseller-package-pricing', [
            'name' => 'Bundle Internet + Netflix',
            'price' => 250000,
            'is_custom' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.is_custom', true);
    }

    public function test_admin_creates_package_pricing_with_explicit_reseller_id(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $reseller = Reseller::factory()->for($tenant)->create();

        $response = $this->actingAs($admin)->postJson('/api/v1/reseller-package-pricing', [
            'name' => 'Paket Admin-assigned',
            'price' => 99000,
            'reseller_id' => $reseller->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.reseller_id', $reseller->id);
    }

    public function test_admin_without_reseller_id_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        $this->actingAs($admin)
            ->postJson('/api/v1/reseller-package-pricing', ['name' => 'No Reseller', 'price' => 1000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reseller_id']);
    }

    public function test_reseller_owner_can_update_own_package_pricing(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->for($tenant)->create();
        $owner = $this->resellerOwner($reseller);
        $pricing = ResellerPackagePricing::factory()->for($reseller, 'reseller')->create();

        $response = $this->actingAs($owner)->putJson("/api/v1/reseller-package-pricing/{$pricing->id}", [
            'price' => 175000,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('reseller_package_pricing', ['id' => $pricing->id, 'price' => 175000.00]);
    }

    public function test_reseller_owner_cannot_access_another_resellers_package_pricing(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->for($tenant)->create();
        $resellerB = Reseller::factory()->for($tenant)->create();
        $ownerA = $this->resellerOwner($resellerA);
        $pricingB = ResellerPackagePricing::factory()->for($resellerB, 'reseller')->create();

        // Route-model-binding is scoped by ResellerScope for a caller with an
        // active reseller context, so another reseller's row is invisible —
        // 404, not 403 (same isolation shape as TenantScope).
        $this->actingAs($ownerA)
            ->getJson("/api/v1/reseller-package-pricing/{$pricingB->id}")
            ->assertNotFound();

        $this->actingAs($ownerA)
            ->putJson("/api/v1/reseller-package-pricing/{$pricingB->id}", ['price' => 1])
            ->assertNotFound();
    }

    public function test_reseller_package_pricing_index_only_lists_own_resellers_pricing(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->for($tenant)->create();
        $resellerB = Reseller::factory()->for($tenant)->create();
        $ownerA = $this->resellerOwner($resellerA);
        ResellerPackagePricing::factory()->for($resellerA, 'reseller')->create(['name' => 'Package A']);
        ResellerPackagePricing::factory()->for($resellerB, 'reseller')->create(['name' => 'Package B']);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/reseller-package-pricing');

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Package A'));
        $this->assertFalse($names->contains('Package B'));
    }

    public function test_deactivate_via_update_and_delete(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->for($tenant)->create();
        $owner = $this->resellerOwner($reseller);
        $pricing = ResellerPackagePricing::factory()->for($reseller, 'reseller')->create();

        $this->actingAs($owner)
            ->deleteJson("/api/v1/reseller-package-pricing/{$pricing->id}")
            ->assertOk();

        $this->assertSoftDeleted('reseller_package_pricing', ['id' => $pricing->id]);
    }
}
