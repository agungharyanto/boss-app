<?php

namespace Tests\Feature\Installation;

use App\Enums\TechnicianStatus;
use App\Models\Reseller;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * v0.12.3 — technician:token (command) + POST /technicians/{technician}/token
 * (endpoint), keduanya lewat App\Services\Installation\TechnicianTokenService.
 */
class TechnicianTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ── command ──────────────────────────────────────────────────────

    public function test_command_revokes_old_tokens_and_issues_a_new_one(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        $user->createToken('old-token-1');
        $user->createToken('old-token-2');
        $this->assertSame(2, PersonalAccessToken::where('tokenable_id', $user->id)->count());

        $this->artisan('technician:token', ['technician_id' => $technician->id])
            ->assertExitCode(0);

        $this->assertSame(1, PersonalAccessToken::where('tokenable_id', $user->id)->count());
        $this->assertSame('technician-api', PersonalAccessToken::where('tokenable_id', $user->id)->value('name'));
    }

    public function test_command_fails_clearly_for_a_nonexistent_technician(): void
    {
        $this->artisan('technician:token', ['technician_id' => 999999])
            ->assertExitCode(1);
    }

    public function test_command_blocks_an_inactive_technician(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $technician = Technician::factory()->create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => TechnicianStatus::Inactive,
        ]);

        $this->artisan('technician:token', ['technician_id' => $technician->id])
            ->assertExitCode(1);

        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $user->id)->count());
    }

    // ── endpoint ─────────────────────────────────────────────────────

    public function test_admin_can_generate_a_token_for_a_technician(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/technicians/{$technician->id}/token")
            ->assertOk();

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);
        $this->assertMatchesRegularExpression('/^\d+\|[A-Za-z0-9]+$/', $token);
    }

    public function test_endpoint_revokes_old_tokens_too(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);
        $user->createToken('stale');

        $this->actingAs($admin)->postJson("/api/v1/technicians/{$technician->id}/token")->assertOk();

        $this->assertSame(1, PersonalAccessToken::where('tokenable_id', $user->id)->count());
    }

    public function test_endpoint_returns_422_for_an_inactive_technician(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $technician = Technician::factory()->create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => TechnicianStatus::Inactive,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/technicians/{$technician->id}/token")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * TechnicianPolicy::manage() — reseller owner boleh generate token
     * utk teknisi RESELLER-NYA SENDIRI (sama posture technicians.manage
     * admin-wide vs reseller_users membership yang sudah ada).
     */
    public function test_a_resellers_own_owner_can_generate_a_token_for_their_own_technician(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        $techUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $technician = Technician::factory()->forReseller($reseller)->create(['user_id' => $techUser->id]);

        $this->actingAs($owner)
            ->postJson("/api/v1/technicians/{$technician->id}/token")
            ->assertOk();
    }

    /**
     * Technician lain (tanpa technicians.manage/reseller membership atas
     * technician TARGET) tidak boleh generate token utk technician lain —
     * ditegaskan eksplisit di instruksi.
     */
    public function test_a_technician_cannot_generate_a_token_for_a_different_technician(): void
    {
        $tenant = Tenant::factory()->create();
        $actingUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $actingUser->assignRole('teknisi');
        Technician::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $actingUser->id]);

        $targetUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $targetTechnician = Technician::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $targetUser->id]);

        $this->actingAs($actingUser)
            ->postJson("/api/v1/technicians/{$targetTechnician->id}/token")
            ->assertForbidden();
    }
}
