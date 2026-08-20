<?php

namespace Tests\Feature\Network;

use App\Exceptions\NasApiUserProvisioningException;
use App\Models\Nas;
use App\Models\Tenant;
use App\Services\Network\Contracts\RouterOsGateway;
use App\Services\Network\NasApiUserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NasApiUserProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private function bindGateway(bool $success, ?string $message = null): void
    {
        $this->app->bind(RouterOsGateway::class, fn () => new class($success, $message) implements RouterOsGateway
        {
            public array $calls = [];

            public function __construct(private readonly bool $success, private readonly ?string $message) {}

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
                return ['success' => $this->success, 'message' => $this->message];
            }
        });
    }

    public function test_provision_with_admin_credential_creates_a_dedicated_username_and_updates_the_nas(): void
    {
        $this->bindGateway(success: true);

        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id, 'api_username' => null, 'api_password' => null]);

        $result = app(NasApiUserProvisioningService::class)->provisionWithAdminCredential($nas, 'admin-user', 'admin-pass');

        $this->assertSame("boss-app-api-{$nas->id}", $result->api_username);
        $this->assertNotNull($result->api_password);
        $this->assertNotSame('admin-pass', $result->api_password);
    }

    public function test_provision_with_admin_credential_throws_on_gateway_failure_and_does_not_persist_anything(): void
    {
        $this->bindGateway(success: false, message: 'invalid admin credential');

        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id, 'api_username' => 'old-user', 'api_password' => 'old-pass']);

        $this->expectException(NasApiUserProvisioningException::class);

        try {
            app(NasApiUserProvisioningService::class)->provisionWithAdminCredential($nas, 'admin-user', 'wrong-pass');
        } finally {
            $this->assertSame('old-user', $nas->fresh()->api_username);
            $this->assertSame('old-pass', $nas->fresh()->api_password);
        }
    }

    public function test_rotate_uses_the_nas_own_current_credential_and_keeps_the_same_username(): void
    {
        $this->bindGateway(success: true);

        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id, 'api_username' => 'boss-app-api-1', 'api_password' => 'old-password']);

        $result = app(NasApiUserProvisioningService::class)->rotate($nas);

        $this->assertSame('boss-app-api-1', $result->api_username);
        $this->assertNotSame('old-password', $result->api_password);
    }

    public function test_rotate_throws_when_nas_has_no_existing_api_credential(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id, 'api_username' => null, 'api_password' => null]);

        $this->expectException(NasApiUserProvisioningException::class);

        app(NasApiUserProvisioningService::class)->rotate($nas);
    }
}
