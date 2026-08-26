<?php

namespace Tests\Feature\Api;

use App\Models\TaxComponent;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxComponentApiTest extends TestCase
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

    private function nonAdmin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('billing');

        return $user;
    }

    public function test_admin_can_create_tax_component(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        $response = $this->actingAs($admin)->postJson('/api/v1/tax-components', [
            'code' => 'PPN',
            'name' => 'PPN',
            'type' => 'percentage',
            'rate' => 11,
            'effective_from' => now()->startOfMonth()->toDateString(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.code', 'PPN');
        $this->assertEquals(11, $response->json('data.rate'));
        $this->assertDatabaseHas('tax_components', ['code' => 'PPN', 'tenant_id' => $tenant->id]);
    }

    public function test_non_admin_cannot_create_tax_component(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = $this->nonAdmin($tenant);

        $this->actingAs($billing)
            ->postJson('/api/v1/tax-components', ['code' => 'X', 'name' => 'X', 'type' => 'fixed', 'rate' => 1000, 'effective_from' => now()->toDateString()])
            ->assertForbidden();
    }

    public function test_update_rate_effective_dates_a_new_row_and_closes_the_old_one(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $ppn = TaxComponent::factory()->for($tenant)->create(['code' => 'PPN', 'rate' => 11, 'effective_from' => now()->startOfMonth()->toDateString()]);

        $response = $this->actingAs($admin)->postJson("/api/v1/tax-components/{$ppn->id}/update-rate", [
            'rate' => 12,
            'effective_from' => now()->addMonth()->startOfMonth()->toDateString(),
        ]);

        $response->assertOk();
        $this->assertEquals(12, $response->json('data.rate'));
        $response->assertJsonPath('data.code', 'PPN');
        $this->assertNotEquals($ppn->id, $response->json('data.id'));

        $this->assertEquals(
            now()->addMonth()->startOfMonth()->subDay()->toDateString(),
            $ppn->fresh()->effective_to->toDateString()
        );
    }

    public function test_generic_update_cannot_change_rate(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $ppn = TaxComponent::factory()->for($tenant)->create(['code' => 'PPN', 'rate' => 11]);

        $this->actingAs($admin)->putJson("/api/v1/tax-components/{$ppn->id}", [
            'name' => 'PPN Renamed',
        ])->assertOk();

        $this->assertDatabaseHas('tax_components', ['id' => $ppn->id, 'name' => 'PPN Renamed', 'rate' => 11.0000]);
    }
}
