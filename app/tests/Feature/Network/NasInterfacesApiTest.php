<?php

namespace Tests\Feature\Network;

use App\Models\Nas;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\Contracts\RouterOsGateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Revisi Grup Profil (Langkah 1) — GET /nas/{nas}/interfaces, the REST
 * twin of NetworkProfileGroupIndex's own interfaceOptionsForNas(). Real
 * router verification (never a raw-socket call in this suite) was done
 * manually against ro-hotspot.bajastu.id — see CHANGELOG.md/CLAUDE.md.
 */
class NasInterfacesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function bindGateway(array $interfaces): void
    {
        $this->app->bind(RouterOsGateway::class, fn () => new class($interfaces) implements RouterOsGateway
        {
            public function __construct(private readonly array $interfaces) {}

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
                return $this->interfaces;
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

    public function test_admin_can_list_a_nas_own_interfaces(): void
    {
        $this->bindGateway([
            ['name' => 'ether1', 'type' => 'ether'],
            ['name' => 'vlan110-PPPoE-10Mbps', 'type' => 'vlan'],
        ]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->givePermissionTo('nas.view');

        $response = $this->actingAs($admin)->getJson("/api/v1/nas/{$nas->id}/interfaces");

        $response->assertOk();
        $response->assertJsonPath('data.1.name', 'vlan110-PPPoE-10Mbps');
        $response->assertJsonCount(2, 'data');
    }

    public function test_a_role_without_nas_view_cannot_list_interfaces(): void
    {
        $this->bindGateway([]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        $response = $this->actingAs($user)->getJson("/api/v1/nas/{$nas->id}/interfaces");

        $response->assertForbidden();
    }

    public function test_an_unreachable_nas_returns_an_empty_list_not_an_error(): void
    {
        $this->bindGateway([]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->givePermissionTo('nas.view');

        $response = $this->actingAs($admin)->getJson("/api/v1/nas/{$nas->id}/interfaces");

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }
}
