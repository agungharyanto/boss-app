<?php

namespace App\Services\Network;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnProtocol;
use App\Models\Nas;
use App\Models\VpnAccount;
use App\Services\Network\Contracts\NasWireguardInspector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * v0.16 — "Cek Koneksi RADIUS" per-NAS diagnostic (menu NAS). Runs the
 * same 3-hop check a human did by hand during the 2026-08-31 ro-hotspot
 * incident and reports EACH hop's result, so it's obvious exactly where
 * the path breaks:
 *
 *   1. tunnel WireGuard sisi server  — read the shared wg-status-* /
 *      heartbeat-* files the WireGuard node containers write into
 *      vpn_wg_data (boss-app has no docker exec — same mechanism
 *      VpnSyncRouteFragments already uses).
 *   2. router -> FreeRADIUS lewat tunnel — an ACTIVE /ping issued from
 *      the NAS's own router (NasWireguardInspector::pingFromRouter).
 *   3. interface WireGuard sisi router — /interface/wireguard/peers from
 *      the router's own point of view.
 *
 * Self-solve is limited to SAFE, REVERSIBLE, per-NAS actions (retrigger
 * this NAS's own peer handshake + re-sync its route fragment). Anything
 * that could touch another NAS (recreate a WireGuard node container) is
 * only ever a SUGGESTION string, never an executed action.
 */
class NasRadiusDiagnosticService
{
    private const HANDSHAKE_FRESH_SECONDS = 180;

    private const HANDSHAKE_STALE_SECONDS = 600;

    private const HEARTBEAT_STALE_SECONDS = 30;

    public function __construct(private readonly NasWireguardInspector $inspector) {}

    /**
     * @return array<string, mixed>
     */
    public function run(Nas $nas): array
    {
        $account = VpnAccount::query()
            ->where('nas_id', $nas->id)
            ->where('protocol', VpnProtocol::WireGuard)
            ->where('status', VpnAccountStatus::Active)
            ->first();

        $suggestions = [];

        [$tunnel, $nodeAlive, $handshakeFresh] = $this->stepTunnel($nas, $account, $suggestions);
        $freeradius = $this->stepFreeradiusReachable($nas, $suggestions);
        $router = $this->stepRouterWireguard($nas, $suggestions);

        $steps = [$tunnel, $freeradius, $router];
        $statuses = array_column($steps, 'status');

        $overall = in_array('fail', $statuses, true)
            ? 'down'
            : (in_array('warn', $statuses, true) || in_array('skip', $statuses, true) ? 'degraded' : 'ok');

        return [
            'nas' => ['id' => $nas->id, 'name' => $nas->name, 'mikrotik_ip' => $nas->mikrotik_ip],
            'account' => $account !== null ? ['id' => $account->id, 'internal_ip' => $account->internal_ip] : null,
            'ran_at' => now()->toDateTimeString(),
            'steps' => $steps,
            'overall' => $overall,
            // Retrigger helps a stale/missing handshake ONLY while the node
            // container is actually alive — a dead node needs a container
            // recreate, which retrigger can't do.
            'self_solve_available' => $nodeAlive && ! $handshakeFresh,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Safe self-solve: retrigger this NAS's own peer handshake + re-sync
     * its route fragment. Never touches a container or another NAS.
     *
     * @return array<string, mixed>
     */
    public function selfSolve(Nas $nas): array
    {
        $retriggered = $this->inspector->retriggerPeerHandshake($nas);
        Artisan::call('vpn:sync-route-fragments');

        return [
            'retriggered' => $retriggered,
            'route_synced' => true,
            'message' => $retriggered
                ? 'Handshake peer WireGuard NAS ini di-trigger ulang & route fragment disinkronkan. Jalankan cek lagi dalam ~15 detik.'
                : 'Route fragment disinkronkan, tapi peer WireGuard di router tidak bisa di-trigger ulang (API tidak terjangkau / peer tidak ada).',
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $suggestions
     * @return array{0: array<string, mixed>, 1: bool, 2: bool} [step, nodeAlive, handshakeFresh]
     */
    private function stepTunnel(Nas $nas, ?VpnAccount $account, array &$suggestions): array
    {
        $label = 'Tunnel WireGuard (sisi server)';

        if ($account === null) {
            $suggestions[] = ['severity' => 'high', 'label' => 'NAS ini belum punya akun WireGuard aktif — buka Script Generator dan klik Generate.'];

            return [$this->step('tunnel', $label, 'fail', 'Tidak ada akun WireGuard aktif untuk NAS ini.'), false, false];
        }

        $statusDir = dirname((string) config('services.vpn.wg_peers_dir'));
        $best = $this->freshestPeerHandshake($statusDir, (string) $account->public_key);

        if ($best === null) {
            return [$this->step('tunnel', $label, 'fail', 'Peer NAS ini belum pernah handshake di node WireGuard manapun (atau file status node tidak ada).'), $this->anyNodeAlive($statusDir), false];
        }

        [$nodeHostname, $ageSeconds] = $best;
        $nodeAlive = $this->heartbeatFresh($statusDir, $nodeHostname);

        if (! $nodeAlive) {
            $suggestions[] = ['severity' => 'high', 'label' => "Node WireGuard \"{$nodeHostname}\" tampak mati (heartbeat basi). Perlu recreate container node — di LUAR tombol self-solve karena bisa mempengaruhi NAS lain."];

            return [$this->step('tunnel', $label, 'fail', "Peer terakhir handshake {$ageSeconds}s lalu di node \"{$nodeHostname}\", tapi heartbeat node itu basi — node kemungkinan mati."), false, false];
        }

        if ($ageSeconds <= self::HANDSHAKE_FRESH_SECONDS) {
            return [$this->step('tunnel', $label, 'ok', "Handshake {$ageSeconds}s lalu di node \"{$nodeHostname}\"."), true, true];
        }

        if ($ageSeconds <= self::HANDSHAKE_STALE_SECONDS) {
            return [$this->step('tunnel', $label, 'warn', "Handshake agak lama ({$ageSeconds}s lalu) di node \"{$nodeHostname}\" — mungkin normal (rekey WireGuard ~120s), coba self-solve kalau berlanjut."), true, false];
        }

        return [$this->step('tunnel', $label, 'fail', "Handshake terakhir {$ageSeconds}s lalu di node \"{$nodeHostname}\" — tunnel kemungkinan putus dari sisi router."), true, false];
    }

    /**
     * @param  array<int, array<string, string>>  $suggestions
     * @return array<string, mixed>
     */
    private function stepFreeradiusReachable(Nas $nas, array &$suggestions): array
    {
        $label = 'Router → FreeRADIUS lewat tunnel';
        $freeradiusIp = (string) config('services.vpn.freeradius_internal_ip');

        $result = $this->inspector->pingFromRouter($nas, $freeradiusIp, 3);

        if ($result === null) {
            $suggestions[] = ['severity' => 'medium', 'label' => 'API router NAS tidak bisa dihubungi — cek api_username / api_port / firewall. (Untuk user API dengan policy terbatas, ping butuh policy "test".)'];

            return $this->step('freeradius', $label, 'skip', "API router tidak terjangkau — ping FreeRADIUS ({$freeradiusIp}) dari router dilewati.");
        }

        return $result
            ? $this->step('freeradius', $label, 'ok', "Router berhasil ping FreeRADIUS ({$freeradiusIp}) lewat tunnel.")
            : $this->step('freeradius', $label, 'fail', "Router TIDAK bisa menjangkau FreeRADIUS ({$freeradiusIp}) lewat tunnel — paket RADIUS dari NAS ini tidak akan sampai.");
    }

    /**
     * @param  array<int, array<string, string>>  $suggestions
     * @return array<string, mixed>
     */
    private function stepRouterWireguard(Nas $nas, array &$suggestions): array
    {
        $label = 'Interface WireGuard (sisi router)';
        $s = $this->inspector->peerStatus($nas);

        if ($s === null) {
            return $this->step('router_wg', $label, 'skip', 'API router tidak terjangkau — status interface WireGuard dari sisi router dilewati.');
        }

        if (! $s['interface_running']) {
            return $this->step('router_wg', $label, 'fail', 'Interface boss-vpn-wireguard di router tidak running.');
        }

        if (! $s['peer_found']) {
            $suggestions[] = ['severity' => 'high', 'label' => 'Router tidak punya peer WireGuard — apply ulang script WireGuard dari Script Generator.'];

            return $this->step('router_wg', $label, 'fail', 'Interface running tapi tidak ada peer boss-vpn-wireguard.');
        }

        $hs = trim((string) $s['last_handshake']);
        $endpoint = $s['endpoint'] ?? '?';
        $detail = "peer endpoint {$endpoint}, last-handshake ".($hs === '' ? 'NEVER' : $hs).", rx={$s['rx']} tx={$s['tx']}";

        if ($hs === '' || str_contains(strtolower($hs), 'never')) {
            return $this->step('router_wg', $label, 'fail', "Router: {$detail} — router terus mencoba, server tidak menjawab.");
        }

        if ((int) $s['rx'] === 0) {
            return $this->step('router_wg', $label, 'warn', "Router: {$detail} — router mengirim (tx>0) tapi belum menerima balasan (rx=0).");
        }

        return $this->step('router_wg', $label, 'ok', "Router: {$detail}.");
    }

    /**
     * Freshest last-handshake for $publicKey across every wg-status-* file.
     *
     * @return array{0: string, 1: int}|null [nodeHostname, ageSeconds]
     */
    private function freshestPeerHandshake(string $statusDir, string $publicKey): ?array
    {
        if ($publicKey === '') {
            return null;
        }

        $bestHostname = null;
        $bestHandshake = 0;

        foreach (File::glob("{$statusDir}/wg-status-*") as $file) {
            $hostname = str_replace('wg-status-', '', basename($file));
            $lines = preg_split('/\R/', trim((string) File::get($file))) ?: [];

            foreach (array_slice($lines, 1) as $line) {
                $f = explode("\t", $line);

                if (($f[0] ?? null) !== $publicKey) {
                    continue;
                }

                $handshake = (int) ($f[4] ?? 0);

                if ($handshake > $bestHandshake) {
                    $bestHandshake = $handshake;
                    $bestHostname = $hostname;
                }
            }
        }

        if ($bestHostname === null || $bestHandshake === 0) {
            return null;
        }

        return [$bestHostname, max(0, time() - $bestHandshake)];
    }

    private function heartbeatFresh(string $statusDir, string $nodeHostname): bool
    {
        $path = "{$statusDir}/heartbeat-{$nodeHostname}";

        if (! File::exists($path)) {
            return false;
        }

        return (time() - (int) trim((string) File::get($path))) <= self::HEARTBEAT_STALE_SECONDS;
    }

    private function anyNodeAlive(string $statusDir): bool
    {
        foreach (File::glob("{$statusDir}/heartbeat-*") as $file) {
            if ((time() - (int) trim((string) File::get($file))) <= self::HEARTBEAT_STALE_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function step(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
