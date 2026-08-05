<?php

namespace App\Services\Network;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnProtocol;
use App\Exceptions\CoaTimeoutException;
use App\Exceptions\CoaUnavailableException;
use App\Models\Nas;
use App\Models\VpnAccount;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Sends CoA-Request (change attributes of an active session) or
 * Disconnect-Request (force-drop it) to a NAS's own RouterOS `/radius
 * incoming` listener — the OPPOSITE direction from auth/acct, where the
 * NAS is the client and FreeRADIUS is the server. Here BOSS App is the
 * Dynamic Authorization Client (RFC 5176) and the NAS is the server.
 *
 * **Why this runs via a queue file + the freeradius container's own
 * coa-worker.sh, not a direct radclient call from boss-app**: RouterOS's
 * `/radius incoming` validates an incoming CoA/Disconnect-Request against
 * the `address=` of a matching `/radius` client entry (confirmed against a
 * real router, not assumed) — every NAS's own `/radius add address=...`
 * (see MikrotikScriptGenerator::radiusScript()) is configured with
 * FREERADIUS_INTERNAL_IP specifically. boss-app's own container has a
 * DIFFERENT boss-network IP, so a packet sent directly from here would be
 * silently rejected by the NAS. Only the freeradius container itself sits
 * at that exact address, so the actual `radclient` invocation has to
 * happen there — boss-app has no Docker exec access to another container
 * (same stance as every other cross-container coordination in this
 * codebase), so it hands off via the SAME shared-volume-plus-poll-loop
 * pattern already used for NAS listen/clients config (see
 * FreeradiusVirtualServerService), just with a much tighter ~3s poll
 * cadence and a request/result file pair instead of a fire-and-forget
 * write.
 *
 * **Which VPN account's internal_ip is targeted**: the NAS's most
 * recently active OpenVPN or WireGuard account — L2TP/IPsec is
 * deliberately excluded (existing known limitation: ESP never actually
 * wraps L2TP traffic, so a CoA packet couldn't reach it over that tunnel
 * even if this class tried).
 *
 * **Known, documented limitation (multi-node pool, v0.6.4)**: the reverse
 * route this depends on (see docker/freeradius/entrypoint.sh's
 * refresh_coa_routes()) only reaches the POOL OWNER node (node1) for each
 * protocol's tunnel subnet. If a NAS has actually failed over to a sibling
 * node (auto-switch) at the moment CoA is sent, delivery can fail even
 * though the account row and its internal_ip are still perfectly valid —
 * WireGuard specifically has no way to send data to a peer this
 * particular daemon has never itself handshaked with. Not solved here;
 * flagged as backlog (a smarter multi-node-aware CoA router) rather than
 * silently pretended away.
 */
class CoaService
{
    private const POLL_TIMEOUT_SECONDS = 15;

    public function disconnect(Nas $nas, string $username): array
    {
        return $this->send($nas, $username, disconnect: true);
    }

    public function coaRequest(Nas $nas, string $username): array
    {
        return $this->send($nas, $username, disconnect: false);
    }

    /**
     * @return array{ok: bool, raw: string}
     */
    private function send(Nas $nas, string $username, bool $disconnect): array
    {
        $account = VpnAccount::query()
            ->where('nas_id', $nas->id)
            ->where('status', VpnAccountStatus::Active)
            ->whereIn('protocol', [VpnProtocol::OpenVpn, VpnProtocol::WireGuard])
            ->latest('id')
            ->first();

        if ($account === null) {
            throw new CoaUnavailableException(
                "NAS '{$nas->name}' tidak punya akun VPN OpenVPN/WireGuard aktif — CoA/Disconnect butuh salah satu dari dua protokol itu (L2TP/IPsec known limitation, belum didukung)."
            );
        }

        File::ensureDirectoryExists($this->queueDir());

        $requestId = (string) Str::uuid();
        $requestFile = "{$this->queueDir()}/{$requestId}.json";
        $resultFile = "{$this->queueDir()}/{$requestId}.result.json";

        File::put($requestFile, json_encode([
            'target_ip' => $account->internal_ip,
            'port' => $nas->coa_port,
            'secret' => $nas->radius_secret,
            'username' => $username,
            'type' => $disconnect ? 'disconnect' : 'coa',
        ]));

        $deadline = now()->addSeconds(self::POLL_TIMEOUT_SECONDS);
        while (now()->lt($deadline)) {
            if (File::exists($resultFile)) {
                $result = json_decode(File::get($resultFile), true);
                File::delete($resultFile);

                return $result;
            }
            usleep(300_000);
        }

        File::delete($requestFile);

        throw new CoaTimeoutException(
            'Tidak ada respons dari coa-worker dalam '.self::POLL_TIMEOUT_SECONDS.' detik — cek apakah container freeradius sehat.'
        );
    }

    private function queueDir(): string
    {
        return config('services.freeradius.nas_config_dir').'/coa-queue';
    }
}
