<?php

namespace Tests\Feature\Network;

use App\Models\Nas;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\Contracts\RouterOsGateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NasProvisionApiUserApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function bindGateway(bool $success): void
    {
        $this->app->bind(RouterOsGateway::class, fn () => new class($success) implements RouterOsGateway
        {
            public function __construct(private readonly bool $success) {}

            public function ping(Nas $nas): array
            {
                return ['online' => true, 'message' => null];
            }


            public function pingHost(Nas $nas, string $targetIp, int $count = 2): bool
            {
                return true;
            }
            public function provisionApiUser(Nas $nas, string $connectAsUsername, string $connectAsPassword, string $newApiUsername, string $newApiPassword): array
            {
                return ['success' => $this->success, 'message' => $this->success ? null : 'bad admin credential'];
            }
        });
    }

    public function test_admin_can_provision_api_user_for_their_own_nas(): void
    {
        $this->bindGateway(success: true);

        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->givePermissionTo('nas.manage');

        $response = $this->actingAs($admin)->postJson("/api/v1/nas/{$nas->id}/provision-api-user", [
            'admin_username' => 'router-admin',
            'admin_password' => 'router-admin-password',
        ]);

        $response->assertOk();
        $this->assertSame("boss-app-api-{$nas->id}", $nas->fresh()->api_username);
    }

    public function test_admin_username_and_password_are_required(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->givePermissionTo('nas.manage');

        $response = $this->actingAs($admin)->postJson("/api/v1/nas/{$nas->id}/provision-api-user", []);

        $response->assertUnprocessable();
    }

    public function test_returns_422_when_router_rejects_the_admin_credential(): void
    {
        $this->bindGateway(success: false);

        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id, 'api_username' => 'old', 'api_password' => 'old-pass']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->givePermissionTo('nas.manage');

        $response = $this->actingAs($admin)->postJson("/api/v1/nas/{$nas->id}/provision-api-user", [
            'admin_username' => 'router-admin',
            'admin_password' => 'wrong',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('success', false);
        $this->assertSame('old', $nas->fresh()->api_username);
    }
}
