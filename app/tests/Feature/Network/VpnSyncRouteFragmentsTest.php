<?php

namespace Tests\Feature\Network;

use App\Enums\VpnAccountStatus;
use App\Models\Nas;
use App\Models\OltDevice;
use App\Models\VpnAccount;
use App\Models\VpnWireguardNasBlock;
use App\Services\Network\Contracts\RouterOsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * v0.8.1 fragment+reconcile (replaces the OSPF experiment — see CLAUDE.md).
 */
class VpnSyncRouteFragmentsTest extends TestCase
{
    use RefreshDatabase;

    private string $routesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->routesDir = sys_get_temp_dir().'/vpn-routes-test-'.uniqid();

        config([
            'services.vpn.routes_dir' => $this->routesDir,
            'services.vpn.wireguard_node_ips' => [
                51820 => '172.28.0.11',
                51821 => '172.28.0.4',
                51822 => '172.28.0.5',
            ],
            'services.vpn.olt_management_subnet' => '10.168.100.0/24',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->routesDir);

        parent::tearDown();
    }

    /**
     * @param  array<int, int>  $portByNasId  nas_id => current-endpoint-port,
     *                                        or absent = "undetectable"
     */
    private function bindGateway(array $portByNasId): void
    {
        $this->app->bind(RouterOsGateway::class, fn () => new class($portByNasId) implements RouterOsGateway
        {
            public function __construct(private readonly array $portByNasId) {}

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
                return $this->portByNasId[$nas->id] ?? null;
            }
        });
    }

    private function fragmentPath(Nas $nas): string
    {
        return "{$this->routesDir}/nas-{$nas->id}.conf";
    }

    public function test_writes_router_and_tr069_subnet_lines_for_an_active_nas(): void
    {
        $nas = Nas::factory()->provisioned()->create(['tr069_management_subnet' => '10.1.0.0/20']);
        $account = VpnAccount::factory()->create([
            'nas_id' => $nas->id, 'protocol' => 'wireguard', 'status' => VpnAccountStatus::Active,
            'username' => "nas-{$nas->id}",
        ]);
        VpnWireguardNasBlock::factory()->create([
            'nas_id' => $nas->id, 'vpn_server_id' => $account->vpn_server_id,
            'gateway_ip' => '172.23.195.1', 'router_ip' => '172.23.195.2',
        ]);
        $this->bindGateway([$nas->id => 51821]);

        $this->artisan('vpn:sync-route-fragments')->assertSuccessful();

        $content = File::get($this->fragmentPath($nas));
        $this->assertStringContainsString('172.23.195.2/32 via 172.28.0.4', $content);
        // v0.8.4 — gateway_ip also needs a route now, not just router_ip
        // (see this command's own docblock: the per-NAS FreeRADIUS SNAT
        // rule in docker/wireguard/entrypoint.sh rewrites the NAS's real
        // source to gateway_ip, so a consumer needs a route back to it).
        $this->assertStringContainsString('172.23.195.1/32 via 172.28.0.4', $content);
        $this->assertStringContainsString('10.1.0.0/20 via 172.28.0.4', $content);
        // No OLT registered for this NAS — must NOT appear.
        $this->assertStringNotContainsString('10.168.100.0/24', $content);
    }

    public function test_includes_olt_subnet_only_when_nas_has_a_registered_olt(): void
    {
        $nas = Nas::factory()->provisioned()->create(['tr069_management_subnet' => null]);
        $account = VpnAccount::factory()->create([
            'nas_id' => $nas->id, 'protocol' => 'wireguard', 'status' => VpnAccountStatus::Active,
            'username' => "nas-{$nas->id}",
        ]);
        VpnWireguardNasBlock::factory()->create([
            'nas_id' => $nas->id, 'vpn_server_id' => $account->vpn_server_id,
            'gateway_ip' => '172.23.195.5', 'router_ip' => '172.23.195.6',
        ]);
        OltDevice::factory()->create(['nas_id' => $nas->id]);
        $this->bindGateway([$nas->id => 51822]);

        $this->artisan('vpn:sync-route-fragments')->assertSuccessful();

        $content = File::get($this->fragmentPath($nas));
        $this->assertStringContainsString('10.168.100.0/24 via 172.28.0.5', $content);
    }

    public function test_undetectable_current_node_removes_any_existing_fragment_instead_of_leaving_it_stale(): void
    {
        $nas = Nas::factory()->provisioned()->create();
        $account = VpnAccount::factory()->create([
            'nas_id' => $nas->id, 'protocol' => 'wireguard', 'status' => VpnAccountStatus::Active,
            'username' => "nas-{$nas->id}",
        ]);
        VpnWireguardNasBlock::factory()->create([
            'nas_id' => $nas->id, 'vpn_server_id' => $account->vpn_server_id,
        ]);
        File::makeDirectory($this->routesDir, 0777, true);
        File::put($this->fragmentPath($nas), "172.23.195.2/32 via 172.28.0.5\n");

        // Gateway can't determine the port at all (router unreachable, or
        // no matching peer) — router-unreachable and empty-array both mean
        // "no entry for this nas id".
        $this->bindGateway([]);

        $this->artisan('vpn:sync-route-fragments')->assertSuccessful();

        $this->assertFileDoesNotExist($this->fragmentPath($nas));
    }

    public function test_revoked_account_gets_its_fragment_removed_even_if_it_still_exists_on_disk(): void
    {
        $nas = Nas::factory()->provisioned()->create();
        VpnAccount::factory()->create([
            'nas_id' => $nas->id, 'protocol' => 'wireguard', 'status' => VpnAccountStatus::Revoked,
            'username' => "nas-{$nas->id}",
        ]);
        File::makeDirectory($this->routesDir, 0777, true);
        File::put($this->fragmentPath($nas), "172.23.195.2/32 via 172.28.0.5\n");

        $this->bindGateway([$nas->id => 51820]);

        $this->artisan('vpn:sync-route-fragments')->assertSuccessful();

        $this->assertFileDoesNotExist($this->fragmentPath($nas));
    }

    public function test_two_different_nas_on_two_different_current_nodes_each_get_their_own_correct_fragment(): void
    {
        $nasA = Nas::factory()->provisioned()->create();
        $accountA = VpnAccount::factory()->create([
            'nas_id' => $nasA->id, 'protocol' => 'wireguard', 'status' => VpnAccountStatus::Active,
            'username' => "nas-{$nasA->id}",
        ]);
        VpnWireguardNasBlock::factory()->create([
            'nas_id' => $nasA->id, 'vpn_server_id' => $accountA->vpn_server_id,
            'gateway_ip' => '172.23.195.1', 'router_ip' => '172.23.195.2',
        ]);

        $nasB = Nas::factory()->provisioned()->create();
        $accountB = VpnAccount::factory()->create([
            'nas_id' => $nasB->id, 'protocol' => 'wireguard', 'status' => VpnAccountStatus::Active,
            'username' => "nas-{$nasB->id}",
        ]);
        VpnWireguardNasBlock::factory()->create([
            'nas_id' => $nasB->id, 'vpn_server_id' => $accountB->vpn_server_id,
            'gateway_ip' => '172.23.195.5', 'router_ip' => '172.23.195.6',
        ]);

        $this->bindGateway([$nasA->id => 51820, $nasB->id => 51822]);

        $this->artisan('vpn:sync-route-fragments')->assertSuccessful();

        $contentA = File::get($this->fragmentPath($nasA));
        $contentB = File::get($this->fragmentPath($nasB));
        $this->assertStringContainsString('172.23.195.2/32 via 172.28.0.11', $contentA);
        $this->assertStringContainsString('172.23.195.1/32 via 172.28.0.11', $contentA);
        $this->assertStringContainsString('172.23.195.6/32 via 172.28.0.5', $contentB);
        $this->assertStringContainsString('172.23.195.5/32 via 172.28.0.5', $contentB);
    }
}
