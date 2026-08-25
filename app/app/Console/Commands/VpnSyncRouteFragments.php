<?php

namespace App\Console\Commands;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnProtocol;
use App\Models\OltDevice;
use App\Models\VpnAccount;
use App\Models\VpnWireguardNasBlock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * v0.8.1 fragment+reconcile (replaces the OSPF experiment — see CLAUDE.md's
 * "OSPF Dynamic Routing" and "Fragment+Reconcile Routing" sections).
 * BOSS App is the source of truth here: for every active WireGuard NAS
 * account, determine which of the 3 pool nodes its tunnel is CURRENTLY
 * connected to, then write one small fragment file per NAS
 * (`routes/nas-{id}.conf`) listing every subnet a consumer container might
 * need to reach through that NAS's tunnel, "<subnet> via <node_ip>" per
 * line. The 5 consumer containers' own reconcile loops (each one's own
 * docker/*.../entrypoint.sh) just read-and-`ip route replace` these files
 * on a plain polling loop — no routing protocol, no daemon, same idiom
 * already used for the WireGuard peer/address fragments this command
 * deliberately doesn't touch.
 *
 * v0.8.4 — "which node is currently active for this NAS" is answered from
 * `wg-status-{hostname}` files each WireGuard node writes into the shared
 * vpn_wg_data volume (see docker/wireguard/entrypoint.sh's own docblock on
 * that write), NOT by logging into the NAS's own router via RouterOS API
 * (the pre-v0.8.4 mechanism, `RouterOsGateway::currentWireguardEndpointPort()`
 * — removed from this command entirely, see CLAUDE.md's "Router API Login
 * Removal" section for the full investigation). Every NAS's WireGuard peer
 * exists on all 3 nodes at all times (provisioned to all), but only the
 * node currently holding a live handshake is the "current" one — so this
 * reads all 3 nodes' status files, finds the one with the freshest
 * `latest-handshake` timestamp for this NAS's `vpn_accounts.public_key`,
 * and derives the node IP from THAT status file's own listen-port (via
 * the unchanged `services.vpn.wireguard_node_ips` port->IP table).
 * `HANDSHAKE_STALE_THRESHOLD_SECONDS` (300s) is deliberately more generous
 * than the 150s threshold the old (now-disabled) OSPF handshake-watcher
 * used — that mechanism needed to react within seconds to avoid flapping a
 * live route in/out of a routing protocol; this command only runs once a
 * minute and just needs "is this NAS's tunnel plausibly still where we
 * last saw it", not sub-minute precision.
 *
 * Scheduled ->everyMinute() (routes/console.php) — same cadence as
 * VpnCheckNodeHealth, which this command is independent of (that one
 * tracks whether a NODE container is alive at all; this one tracks which
 * node a given NAS's tunnel is CURRENTLY connected to, a different
 * question) — and which already established the exact same
 * shared-volume-file pattern this command now also uses, rather than
 * inventing a new one.
 */
class VpnSyncRouteFragments extends Command
{
    protected $signature = 'vpn:sync-route-fragments';

    protected $description = 'Tulis fragment rute per-NAS WireGuard (node aktif saat ini) untuk dibaca reconcile loop consumer container';

    private const HANDSHAKE_STALE_THRESHOLD_SECONDS = 300;

    public function handle(): int
    {
        $routesDir = config('services.vpn.routes_dir');

        if (! File::isDirectory($routesDir)) {
            File::makeDirectory($routesDir, 0777, true);
        }

        $accounts = VpnAccount::query()
            ->where('protocol', VpnProtocol::WireGuard)
            ->where('status', VpnAccountStatus::Active)
            ->with('nas')
            ->get();

        $activeNasIds = [];

        foreach ($accounts as $account) {
            $nas = $account->nas;

            if ($nas === null || $nas->mikrotik_ip === null) {
                continue;
            }

            $activeNasIds[] = $nas->id;
            $fragmentPath = rtrim($routesDir, '/')."/nas-{$nas->id}.conf";

            $nodeIp = $this->currentNodeIp($account);

            if ($nodeIp === null) {
                // Can't currently determine (or router unreachable) — a
                // stale/wrong route is worse than no route at all (a
                // consumer would silently try the wrong node), so the
                // fragment is removed rather than left with old content.
                if (File::exists($fragmentPath)) {
                    File::delete($fragmentPath);
                    $this->warn("NAS #{$nas->id} ({$nas->name}): node aktif tidak terdeteksi, fragment dihapus.");
                }

                continue;
            }

            $lines = [];

            $block = VpnWireguardNasBlock::where('nas_id', $nas->id)->first();
            if ($block !== null) {
                $lines[] = "{$block->router_ip}/32 via {$nodeIp}";

                // v0.8.4 amendment — a route to $block->gateway_ip was
                // briefly added here (reasoning: "the per-NAS FreeRADIUS
                // SNAT rule rewrites the NAS's source to gateway_ip, so a
                // reply needs a route back to it"). That premise turned
                // out to be wrong and has been REMOVED, not just
                // undocumented — see docker/wireguard/entrypoint.sh's own
                // docblock for the full account: the SNAT rule itself was
                // the actual bug (it should never have rewritten to a
                // 172.23.195.x/tunnel-side address at all — FreeRADIUS's
                // per-NAS `clients {}` ACL only trusts 172.28.0.0/24), so
                // it was fixed to plain MASQUERADE instead, which needs no
                // route back to gateway_ip — FreeRADIUS's reply routes
                // back to whichever 172.28.0.x address the WireGuard node
                // itself masqueraded to, over ordinary boss-network
                // routing, no special-case route required. gateway_ip
                // itself is never a real communication endpoint from
                // FreeRADIUS's side — nothing ever needs to route TO it.
            }

            if (! empty($nas->tr069_management_subnet)) {
                $lines[] = "{$nas->tr069_management_subnet} via {$nodeIp}";
            }

            $oltSubnet = config('services.vpn.olt_management_subnet');
            if (! empty($oltSubnet) && OltDevice::withoutGlobalScopes()->where('nas_id', $nas->id)->exists()) {
                $lines[] = "{$oltSubnet} via {$nodeIp}";
            }

            if ($lines === []) {
                if (File::exists($fragmentPath)) {
                    File::delete($fragmentPath);
                }

                continue;
            }

            File::put($fragmentPath, implode(PHP_EOL, $lines).PHP_EOL);
        }

        // A NAS whose account was revoked since the last run no longer
        // appears in $accounts above — its stale fragment must go too,
        // or a consumer keeps routing toward a NAS that no longer has an
        // active tunnel at all.
        foreach (File::glob(rtrim($routesDir, '/').'/nas-*.conf') as $existingFile) {
            if (preg_match('/nas-(\d+)\.conf$/', $existingFile, $m) && ! in_array((int) $m[1], $activeNasIds, true)) {
                File::delete($existingFile);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Reads every `wg-status-*` file (docker/wireguard/entrypoint.sh's
     * `wg show wg0 dump` write, one file per node) and returns the
     * boss-network IP of whichever node currently has the freshest
     * `latest-handshake` for $account's own public key — or null if no
     * node has ever handshaked with it, the handshake is older than
     * self::HANDSHAKE_STALE_THRESHOLD_SECONDS, or $account has no
     * public_key at all (e.g. a factory-built test row that never went
     * through real provisioning).
     */
    private function currentNodeIp(VpnAccount $account): ?string
    {
        if (empty($account->public_key)) {
            return null;
        }

        $statusDir = dirname(config('services.vpn.wg_peers_dir'));
        $bestPort = null;
        $bestHandshake = 0;

        foreach (File::glob("{$statusDir}/wg-status-*") as $statusFile) {
            $lines = preg_split('/\R/', trim(File::get($statusFile)));

            if ($lines === false || $lines === [] || $lines[0] === '') {
                continue;
            }

            // Line 1: interface (redacted-private-key, own-public-key,
            // listen-port, fwmark) — column 3 (index 2) is the listen
            // port this NAS's peer entry, if found below, is reachable
            // through on THIS node.
            $interfaceFields = explode("\t", $lines[0]);
            $listenPort = isset($interfaceFields[2]) && $interfaceFields[2] !== ''
                ? (int) $interfaceFields[2]
                : null;

            if ($listenPort === null) {
                continue;
            }

            // Remaining lines: one per peer (public-key, preshared-key,
            // endpoint, allowed-ips, latest-handshake, rx, tx, keepalive).
            for ($i = 1; $i < count($lines); $i++) {
                $peerFields = explode("\t", $lines[$i]);

                if (($peerFields[0] ?? null) !== $account->public_key) {
                    continue;
                }

                $handshake = isset($peerFields[4]) ? (int) $peerFields[4] : 0;

                if ($handshake > $bestHandshake) {
                    $bestHandshake = $handshake;
                    $bestPort = $listenPort;
                }
            }
        }

        if ($bestPort === null || $bestHandshake === 0) {
            return null;
        }

        if ((time() - $bestHandshake) > self::HANDSHAKE_STALE_THRESHOLD_SECONDS) {
            return null;
        }

        return config('services.vpn.wireguard_node_ips')[$bestPort] ?? null;
    }
}
