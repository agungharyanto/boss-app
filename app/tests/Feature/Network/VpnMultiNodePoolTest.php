<?php

namespace Tests\Feature\Network;

use App\Enums\VpnServerStatus;
use App\Exceptions\VpnProvisioningException;
use App\Models\Nas;
use App\Models\Tenant;
use App\Models\VpnServer;
use App\Services\Network\VpnProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * v0.6.4 multi-node pool: load-distribution across sibling nodes + the
 * "pool owner" convention (VpnServer::poolOwnerFor()) that keeps
 * internal_ip allocation centralized even though a new account's
 * "preferred" node (vpn_accounts.vpn_server_id, used for current_clients
 * load tracking + which node's public_ip/port the generated Mikrotik
 * script targets) can be any online node with spare capacity.
 */
class VpnMultiNodePoolTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSuccessfulEasyRsa(): void
    {
        Process::fake([
            '*easyrsa*build-client-full*' => Process::result(output: ''),
            '*openssl*' => Process::result(output: "serial=A1B2C3D4E5F60708\n"),
        ]);
    }

    public function test_new_account_prefers_the_online_node_with_the_most_spare_capacity(): void
    {
        $this->fakeSuccessfulEasyRsa();
        $tenant = Tenant::factory()->create();

        // Pool owner (lowest id) — deliberately made near-full to prove
        // selection isn't just "always the pool owner".
        $poolOwner = VpnServer::factory()->create([
            'hostname' => 'vpn-node-1', 'subnet_cidr' => '172.23.201.0/24',
            'current_clients' => 240, 'max_clients' => 250,
        ]);
        $poolOwner->provisionIpPool();

        $roomier = VpnServer::factory()->create([
            'hostname' => 'vpn-node-2', 'subnet_cidr' => '172.23.202.0/24',
            'current_clients' => 5, 'max_clients' => 250,
        ]);

        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $account = app(VpnProvisioningService::class)->provision($nas);

        // Preferred node = the roomier sibling, not the pool owner.
        $this->assertSame($roomier->id, $account->vpn_server_id);
        // ...but the internal_ip still came from the pool owner's pool —
        // $roomier never had provisionIpPool() called on it at all.
        $this->assertStringStartsWith('172.23.201.', $account->internal_ip);

        $this->assertDatabaseHas('vpn_servers', ['id' => $roomier->id, 'current_clients' => 6]);
        $this->assertDatabaseHas('vpn_servers', ['id' => $poolOwner->id, 'current_clients' => 240]);
    }

    public function test_full_and_offline_nodes_are_excluded_from_selection(): void
    {
        $this->fakeSuccessfulEasyRsa();
        $tenant = Tenant::factory()->create();

        $poolOwner = VpnServer::factory()->create(['hostname' => 'vpn-node-1', 'subnet_cidr' => '172.23.203.0/24']);
        $poolOwner->provisionIpPool();

        VpnServer::factory()->create([
            'hostname' => 'vpn-node-2', 'subnet_cidr' => '172.23.204.0/24',
            'status' => VpnServerStatus::Full, 'current_clients' => 250, 'max_clients' => 250,
        ]);
        VpnServer::factory()->create([
            'hostname' => 'vpn-node-3', 'subnet_cidr' => '172.23.205.0/24',
            'status' => VpnServerStatus::Offline, 'current_clients' => 0, 'max_clients' => 250,
        ]);

        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $account = app(VpnProvisioningService::class)->provision($nas);

        // Only the pool owner qualifies (online + spare capacity) — the
        // Full and Offline siblings must never be picked.
        $this->assertSame($poolOwner->id, $account->vpn_server_id);
    }

    public function test_provisioning_throws_when_every_node_is_full_or_offline(): void
    {
        $tenant = Tenant::factory()->create();

        VpnServer::factory()->create(['status' => VpnServerStatus::Full, 'current_clients' => 250, 'max_clients' => 250]);
        VpnServer::factory()->create(['status' => VpnServerStatus::Offline]);

        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(VpnProvisioningException::class);

        app(VpnProvisioningService::class)->provision($nas);
    }
}
