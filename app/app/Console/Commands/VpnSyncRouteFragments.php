<?php

namespace App\Console\Commands;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnProtocol;
use App\Models\OltDevice;
use App\Models\VpnAccount;
use App\Models\VpnWireguardNasBlock;
use App\Services\Network\Contracts\RouterOsGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * v0.8.1 fragment+reconcile (replaces the OSPF experiment — see CLAUDE.md's
 * "OSPF Dynamic Routing" and "Fragment+Reconcile Routing" sections).
 * BOSS App is the source of truth here: for every active WireGuard NAS
 * account, ask the NAS's own router (RouterOsGateway::
 * currentWireguardEndpointPort()) which of the 3 pool nodes its tunnel is
 * CURRENTLY connected to — the only reliable source for this, since
 * auto-switch (v0.6.4) happens entirely client-side on the router, invisible
 * to boss-app any other way — then write one small fragment file per NAS
 * (`routes/nas-{id}.conf`) listing every subnet a consumer container might
 * need to reach through that NAS's tunnel, "<subnet> via <node_ip>" per
 * line. The 5 consumer containers' own reconcile loops (each one's own
 * docker/*.../entrypoint.sh) just read-and-`ip route replace` these files
 * on a plain polling loop — no routing protocol, no daemon, same idiom
 * already used for the WireGuard peer/address fragments this command
 * deliberately doesn't touch.
 *
 * Scheduled ->everyMinute() (routes/console.php) — same cadence as
 * VpnCheckNodeHealth, which this command is independent of (that one
 * tracks whether a NODE container is alive at all; this one tracks which
 * node a given NAS's tunnel is CURRENTLY connected to, a different
 * question).
 */
class VpnSyncRouteFragments extends Command
{
    protected $signature = 'vpn:sync-route-fragments';

    protected $description = 'Tulis fragment rute per-NAS WireGuard (node aktif saat ini) untuk dibaca reconcile loop consumer container';

    public function handle(RouterOsGateway $gateway): int
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

            $port = $gateway->currentWireguardEndpointPort($nas, "NAS {$account->username}");
            $nodeIp = $port !== null ? (config('services.vpn.wireguard_node_ips')[$port] ?? null) : null;

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

                // v0.8.4 — also route to this NAS's own gateway_ip, not
                // just its router_ip. Needed since docker/wireguard/
                // entrypoint.sh's per-NAS FreeRADIUS SNAT rule rewrites
                // the NAS's real source to gateway_ip before the packet
                // reaches FreeRADIUS (fixing a real bug where a single
                // global SNAT target collapsed every NAS's traffic onto
                // ONE NAS's gateway — see that script's own docblock) —
                // without a route back to gateway_ip specifically,
                // FreeRADIUS has no path for its reply and the request
                // still silently times out even with the per-NAS SNAT
                // rule correctly in place. Confirmed for real: a manual
                // `ip route add {gateway_ip}/32 via {nodeIp}` was what
                // actually made the previously-100%-failing ping succeed
                // during this incident's live diagnosis, on top of (not
                // instead of) the per-NAS SNAT rule itself.
                $lines[] = "{$block->gateway_ip}/32 via {$nodeIp}";
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
}
