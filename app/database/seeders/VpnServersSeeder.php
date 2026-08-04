<?php

namespace Database\Seeders;

use App\Enums\VpnProtocol;
use App\Enums\VpnServerStatus;
use App\Models\VpnServer;
use Illuminate\Database\Seeder;

/**
 * v0.6.4 multi-node pool — 3 real nodes x 2 active protocols (OpenVPN,
 * WireGuard; L2TP/IPsec stays a single node, known limitation, not part of
 * the pool). Node1 for each protocol already exists from v0.6.2/v0.6.3
 * (created by hand via tinker back then) — this seeder is idempotent
 * (firstOrCreate keyed on hostname+protocol) so it's safe to re-run and
 * won't duplicate those rows, closing the BOSS-011 reproducibility gap a
 * manual-tinker row would otherwise leave for genuine multi-node
 * infrastructure.
 *
 * Deliberately does NOT call provisionIpPool() on node2/node3 — see
 * VpnServer::poolOwnerFor()'s docblock: VpnProvisioningService always
 * allocates internal_ip from the pool OWNER (node1, lowest id per
 * protocol) regardless of which node a new account's "preferred" server
 * ends up being, so a node2/node3 vpn_ip_pool would just be dead,
 * never-read data. Node2/node3's own subnet_cidr still matters for
 * OpenVPN specifically (its own built-in dynamic ifconfig-pool, entirely
 * orthogonal to our vpn_ip_pool table, is what actually assigns an address
 * to a client that fails over there without a ccd entry).
 */
class VpnServersSeeder extends Seeder
{
    public function run(): void
    {
        $publicIp = config('services.vpn.public_ip');

        $nodes = [
            ['hostname' => 'vpn-node-2', 'protocol' => VpnProtocol::OpenVpn, 'port' => 1195, 'subnet_cidr' => '172.23.199.0/24'],
            ['hostname' => 'vpn-node-3', 'protocol' => VpnProtocol::OpenVpn, 'port' => 1196, 'subnet_cidr' => '172.23.200.0/24'],
            // Subnet matches node1's — informational only for these two
            // (WireGuard's own vpn_ip_pool is never independently
            // provisioned per node, see class docblock).
            ['hostname' => 'vpn-node-2', 'protocol' => VpnProtocol::WireGuard, 'port' => 51821, 'subnet_cidr' => '172.23.195.0/24'],
            ['hostname' => 'vpn-node-3', 'protocol' => VpnProtocol::WireGuard, 'port' => 51822, 'subnet_cidr' => '172.23.195.0/24'],
        ];

        foreach ($nodes as $node) {
            VpnServer::query()->firstOrCreate(
                ['hostname' => $node['hostname'], 'protocol' => $node['protocol']],
                [
                    'public_ip' => $publicIp,
                    'port' => $node['port'],
                    'subnet_cidr' => $node['subnet_cidr'],
                    'max_clients' => 250,
                    'current_clients' => 0,
                    'status' => VpnServerStatus::Online,
                    'is_active' => true,
                ]
            );
        }
    }
}
