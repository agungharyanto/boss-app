<?php

namespace App\Console\Commands;

use App\Enums\VpnProtocol;
use App\Enums\VpnServerStatus;
use App\Models\VpnServer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use LogicException;

/**
 * Scheduled ->everyMinute() (see routes/console.php). boss-app has no
 * Docker socket access (deliberate stance since v0.6.2, see CLAUDE.md), so
 * "is this node's container actually alive" can't be a docker
 * inspect/exec call — instead each openvpn/wireguard node entrypoint
 * writes its own timestamp to the SAME shared volume boss-app already
 * mounts (vpn_pki / vpn_wg_data), one heartbeat file per sibling node
 * (named after NODE_HOSTNAME). A stale/missing heartbeat means the
 * container is down or wedged — treated the same as Offline either way.
 *
 * L2TP/IPsec is deliberately excluded (v0.6.4 scope: known limitation,
 * single node, not part of the multi-node pool this sprint).
 */
class VpnCheckNodeHealth extends Command
{
    protected $signature = 'vpn:check-node-health';

    protected $description = 'Cek heartbeat tiap node VPN (OpenVPN/WireGuard) dan update status online/full/offline';

    /**
     * 3x the entrypoints' own 10s write interval — tolerates one or two
     * missed cycles (e.g. a slow `wg syncconf` iteration) without flapping
     * a genuinely-healthy node to Offline and back.
     */
    private const STALE_THRESHOLD_SECONDS = 30;

    public function handle(): int
    {
        $servers = VpnServer::query()
            ->whereIn('protocol', [VpnProtocol::OpenVpn, VpnProtocol::WireGuard])
            ->get();

        foreach ($servers as $server) {
            $alive = $this->isHeartbeatFresh($this->heartbeatPath($server));

            $newStatus = match (true) {
                ! $alive => VpnServerStatus::Offline,
                $server->current_clients >= $server->max_clients => VpnServerStatus::Full,
                default => VpnServerStatus::Online,
            };

            if ($server->status !== $newStatus) {
                $this->info("{$server->hostname} ({$server->protocol->label()}): {$server->status->value} -> {$newStatus->value}");
                $server->update(['status' => $newStatus]);
            }
        }

        return self::SUCCESS;
    }

    private function heartbeatPath(VpnServer $server): string
    {
        return match ($server->protocol) {
            VpnProtocol::OpenVpn => dirname(config('services.vpn.pki_dir'))."/heartbeat-{$server->hostname}",
            VpnProtocol::WireGuard => dirname(config('services.vpn.wg_peers_dir'))."/heartbeat-{$server->hostname}",
            default => throw new LogicException("VpnCheckNodeHealth has no heartbeat mechanism for protocol {$server->protocol->value}."),
        };
    }

    private function isHeartbeatFresh(string $path): bool
    {
        if (! File::exists($path)) {
            return false;
        }

        $timestamp = (int) trim(File::get($path));

        return (time() - $timestamp) <= self::STALE_THRESHOLD_SECONDS;
    }
}
