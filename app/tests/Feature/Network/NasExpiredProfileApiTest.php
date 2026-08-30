<?php

namespace Tests\Feature\Network;

use App\Jobs\PushExpiredProfileToMikrotikJob;
use App\Jobs\RemoveExpiredProfileFromMikrotikJob;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\Contracts\RouterOsGateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Revisi Grup Profil (Langkah 3) — REST twin of NasIndex's "Profil
 * Pelanggan Expired" modal, PATCH /nas/{nas}/expired-profile. Same
 * anonymous-fake-RouterOsGateway pattern as NasProvisionApiUserApiTest.
 */
class NasExpiredProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function bindGateway(): void
    {
        $this->app->bind(RouterOsGateway::class, fn () => new class implements RouterOsGateway
        {
            public function ping(Nas $nas): array
            {
                return ['online' => true, 'message' => null];
            }

            public function pingHost(Nas $nas, string $targetIp, int $count = 2): bool
            {
                return true;
            }

            public function provisionApiUser(Nas $nas, string $a, string $b, string $c, string $d): array
            {
                return ['success' => true, 'message' => null];
            }

            public function currentWireguardEndpointPort(Nas $nas, string $peerCommentNeedle): ?int
            {
                return null;
            }

            public function syncIpPool(Nas $nas, string $comment, string $name, string $ranges): array
            {
                return ['success' => true, 'message' => null];
            }

            public function removeIpPool(Nas $nas, string $comment): array
            {
                return ['success' => true, 'message' => null];
            }

            public function syncPppProfile(Nas $nas, string $comment, string $name, ?string $remoteAddress, ?string $dnsServer, ?string $parentQueue, ?string $localAddress = null, ?string $rateLimit = null, ?string $sessionTimeout = null): array
            {
                return ['success' => true, 'message' => null];
            }

            public function removePppProfile(Nas $nas, string $comment): array
            {
                return ['success' => true, 'message' => null];
            }

            public function syncHotspotServerPool(Nas $nas, string $poolName): array
            {
                return ['success' => true, 'message' => null];
            }

            public function syncHotspotUserProfile(Nas $nas, string $lookupName, string $targetName, ?string $rateLimit, int $sharedUsers, ?string $sessionTimeout, ?string $addressPool = null): array
            {
                return ['success' => true, 'message' => null];
            }

            public function removeHotspotUserProfile(Nas $nas, string $lookupName): array
            {
                return ['success' => true, 'message' => null];
            }

            public function listInterfaces(Nas $nas): array
            {
                return [];
            }

            public function syncPppoeServer(Nas $nas, string $comment, string $serviceName, string $interfaceName, string $defaultProfile): array
            {
                return ['success' => true, 'message' => null];
            }

            public function removePppoeServer(Nas $nas, string $comment): array
            {
                return ['success' => true, 'message' => null];
            }
        });
    }

    public function test_admin_can_set_expired_profile_pool(): void
    {
        Bus::fake();
        $this->bindGateway();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->givePermissionTo('nas.manage');

        $response = $this->actingAs($admin)->patchJson("/api/v1/nas/{$nas->id}/expired-profile", [
            'customer_ip_pool_id' => $pool->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.expired_ip_pool_id', $pool->id);
        $response->assertJsonPath('data.expired_profile_mikrotik_sync_status', 'pending');
        Bus::assertDispatched(PushExpiredProfileToMikrotikJob::class, fn ($job) => $job->nasId === $nas->id);
    }

    public function test_admin_can_clear_expired_profile_pool(): void
    {
        Bus::fake();
        $this->bindGateway();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $nas->update(['expired_ip_pool_id' => $pool->id]);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->givePermissionTo('nas.manage');

        $response = $this->actingAs($admin)->patchJson("/api/v1/nas/{$nas->id}/expired-profile", [
            'customer_ip_pool_id' => null,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.expired_ip_pool_id', null);
        Bus::assertDispatched(RemoveExpiredProfileFromMikrotikJob::class, fn ($job) => $job->nasId === $nas->id);
    }

    public function test_pool_from_a_different_nas_is_rejected(): void
    {
        $this->bindGateway();
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $poolB = CustomerIpPool::factory()->create(['nas_id' => $nasB->id]);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->givePermissionTo('nas.manage');

        $response = $this->actingAs($admin)->patchJson("/api/v1/nas/{$nasA->id}/expired-profile", [
            'customer_ip_pool_id' => $poolB->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('customer_ip_pool_id');
        $this->assertNull($nasA->fresh()->expired_ip_pool_id);
    }

    public function test_a_role_without_nas_manage_cannot_update_expired_profile(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        $response = $this->actingAs($user)->patchJson("/api/v1/nas/{$nas->id}/expired-profile", []);

        $response->assertForbidden();
    }
}
