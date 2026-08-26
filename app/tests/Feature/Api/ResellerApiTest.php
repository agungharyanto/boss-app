<?php

namespace Tests\Feature\Api;

use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerApiTest extends TestCase
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

    private function nonAdminStaff(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        return $user;
    }

    public function test_admin_can_create_reseller(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        $response = $this->actingAs($admin)->postJson('/api/v1/resellers', [
            'name' => 'Reseller Mitra Jaya',
            'email' => 'mitra@jaya.test',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Reseller Mitra Jaya');
        $response->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('resellers', [
            'name' => 'Reseller Mitra Jaya',
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_non_admin_cannot_create_reseller(): void
    {
        $tenant = Tenant::factory()->create();
        $staff = $this->nonAdminStaff($tenant);

        $this->actingAs($staff)
            ->postJson('/api/v1/resellers', ['name' => 'Should Fail'])
            ->assertForbidden();

        $this->assertDatabaseMissing('resellers', ['name' => 'Should Fail']);
    }

    public function test_admin_can_update_and_suspend_reseller(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $reseller = Reseller::factory()->for($tenant)->create();

        $response = $this->actingAs($admin)->putJson("/api/v1/resellers/{$reseller->id}", [
            'status' => 'suspended',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'suspended');
        $this->assertDatabaseHas('resellers', ['id' => $reseller->id, 'status' => 'suspended']);
    }

    public function test_admin_can_delete_reseller(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $reseller = Reseller::factory()->for($tenant)->create();

        $this->actingAs($admin)->deleteJson("/api/v1/resellers/{$reseller->id}")->assertOk();

        $this->assertSoftDeleted('resellers', ['id' => $reseller->id]);
    }

    public function test_admin_can_attach_and_detach_staff(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $reseller = Reseller::factory()->for($tenant)->create();
        $staffUser = User::factory()->create(['tenant_id' => $tenant->id]);

        $attachResponse = $this->actingAs($admin)->postJson("/api/v1/resellers/{$reseller->id}/users", [
            'user_id' => $staffUser->id,
            'role' => 'owner',
        ]);

        $attachResponse->assertCreated();
        $this->assertDatabaseHas('reseller_users', [
            'reseller_id' => $reseller->id,
            'user_id' => $staffUser->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/resellers/{$reseller->id}/users/{$staffUser->id}")
            ->assertOk();

        // Soft-detach: the row stays, only status flips — not a hard delete.
        $this->assertDatabaseHas('reseller_users', [
            'reseller_id' => $reseller->id,
            'user_id' => $staffUser->id,
            'status' => 'inactive',
        ]);
    }

    public function test_reseller_owner_can_manage_own_staff_but_not_another_resellers(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->for($tenant)->create();
        $resellerB = Reseller::factory()->for($tenant)->create();
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $resellerA->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        $newStaff = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($owner)
            ->postJson("/api/v1/resellers/{$resellerA->id}/users", ['user_id' => $newStaff->id, 'role' => 'staff'])
            ->assertCreated();

        $this->actingAs($owner)
            ->postJson("/api/v1/resellers/{$resellerB->id}/users", ['user_id' => $newStaff->id, 'role' => 'staff'])
            ->assertForbidden();
    }

    public function test_reseller_staff_cannot_manage_staff(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->for($tenant)->create();
        $staffMember = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($staffMember->id, ['role' => 'staff', 'status' => 'active']);

        $anotherUser = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($staffMember)
            ->postJson("/api/v1/resellers/{$reseller->id}/users", ['user_id' => $anotherUser->id, 'role' => 'staff'])
            ->assertForbidden();
    }
}
