<?php

namespace Tests\Feature\Network;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnProtocol;
use App\Models\Nas;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VpnAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class NasDisconnectApiTest extends TestCase
{
    use RefreshDatabase;

    private string $configDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->configDir = sys_get_temp_dir().'/nas-disconnect-api-test-'.uniqid();
        config(['services.freeradius.nas_config_dir' => $this->configDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->configDir);

        parent::tearDown();
    }

    private function fakeCoaWorkerRespondsOk(): void
    {
        $queueDir = "{$this->configDir}/coa-queue";
        File::ensureDirectoryExists($queueDir);

        shell_exec('(for i in $(seq 1 50); do f=$(ls '.$queueDir.'/*.json 2>/dev/null | grep -v ".result.json" | head -1); '
            .'if [ -n "$f" ]; then echo \'{"ok":true,"raw":"Received Disconnect-ACK"}\' > "${f%.json}.result.json"; rm -f "$f"; break; fi; sleep 0.1; done) > /dev/null 2>&1 &');
    }

    public function test_admin_can_disconnect_a_session_on_their_own_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        VpnAccount::factory()->create([
            'nas_id' => $nas->id, 'protocol' => VpnProtocol::OpenVpn, 'status' => VpnAccountStatus::Active,
        ]);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->givePermissionTo('nas.manage');

        $this->fakeCoaWorkerRespondsOk();

        $response = $this->actingAs($admin)->postJson("/api/v1/nas/{$nas->id}/disconnect", ['username' => 'pelanggan-menunggak']);

        $response->assertOk();
        $this->assertTrue($response->json('data.ok'));
    }

    public function test_reseller_cannot_disconnect_a_session_on_another_resellers_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->forReseller($resellerB)->create();

        $ownerA = User::factory()->create(['tenant_id' => $tenant->id]);
        $resellerA->users()->attach($ownerA->id, ['role' => 'owner', 'status' => 'active']);

        $response = $this->actingAs($ownerA)->postJson("/api/v1/nas/{$nasB->id}/disconnect", ['username' => 'someuser']);

        // BelongsToResellerScope excludes nasB from route-model-binding
        // entirely for resellerA's owner — a 404, not a 403, same pattern
        // already established for cross-tenant/cross-reseller isolation
        // elsewhere in this codebase (see TenantIsolationTest).
        $response->assertNotFound();
    }

    public function test_username_is_required(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->givePermissionTo('nas.manage');

        $response = $this->actingAs($admin)->postJson("/api/v1/nas/{$nas->id}/disconnect", []);

        $response->assertUnprocessable();
    }

    public function test_returns_422_when_nas_has_no_active_eligible_account(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->givePermissionTo('nas.manage');

        $response = $this->actingAs($admin)->postJson("/api/v1/nas/{$nas->id}/disconnect", ['username' => 'someuser']);

        $response->assertUnprocessable();
        $response->assertJsonPath('success', false);
    }
}
