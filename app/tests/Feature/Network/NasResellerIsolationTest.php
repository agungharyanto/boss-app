<?php

namespace Tests\Feature\Network;

use App\Models\Nas;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class NasResellerIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function resellerOwner(Tenant $tenant, Reseller $reseller): User
    {
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        return $owner;
    }

    public function test_api_index_only_returns_the_acting_resellers_own_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $nasA = Nas::factory()->forReseller($resellerA)->create();
        Nas::factory()->forReseller($resellerB)->create();

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/nas');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($nasA->id));
        $this->assertCount(1, $ids);
    }

    public function test_api_show_404s_for_another_resellers_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $nasB = Nas::factory()->forReseller($resellerB)->create();

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $this->actingAs($ownerA)
            ->getJson("/api/v1/nas/{$nasB->id}")
            ->assertNotFound();
    }

    public function test_reseller_a_cannot_update_reseller_bs_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $nasB = Nas::factory()->forReseller($resellerB)->create(['name' => 'NAS Original']);

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $this->actingAs($ownerA)
            ->putJson("/api/v1/nas/{$nasB->id}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertDatabaseHas('nas', ['id' => $nasB->id, 'name' => 'NAS Original']);
    }

    public function test_reseller_a_cannot_test_connection_on_reseller_bs_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $nasB = Nas::factory()->forReseller($resellerB)->provisioned()->create();

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $this->actingAs($ownerA)
            ->postJson("/api/v1/nas/{$nasB->id}/test-connection")
            ->assertNotFound();
    }

    public function test_reseller_a_cannot_delete_reseller_bs_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $nasB = Nas::factory()->forReseller($resellerB)->create();

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $this->actingAs($ownerA)
            ->deleteJson("/api/v1/nas/{$nasB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('nas', ['id' => $nasB->id]);
    }

    public function test_isp_admin_sees_and_manages_every_nas_regardless_of_reseller(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $nas = Nas::factory()->forReseller($reseller)->create();

        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'nas.manage', 'guard_name' => 'web']));

        $this->actingAs($admin)
            ->getJson("/api/v1/nas/{$nas->id}")
            ->assertOk();
    }
}
