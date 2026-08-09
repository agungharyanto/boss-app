<?php

namespace App\Services\Network;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnProtocol;
use App\Enums\VpnServerStatus;
use App\Exceptions\VpnScriptGenerationException;
use App\Models\Nas;
use App\Models\VpnAccount;
use App\Models\VpnServer;
use App\Support\CidrRange;
use Illuminate\Support\Facades\File;

/**
 * Orchestrates VpnProvisioningService (data/credentials) +
 * MikrotikScriptGenerator (pure templating) for the Script Generator UI.
 * Kept out of the Livewire component per BOSS-006 (business logic lives in
 * Service/Action classes).
 */
class VpnScriptService
{
    public function __construct(
        private readonly VpnProvisioningService $provisioning,
        private readonly MikrotikScriptGenerator $generator,
        private readonly ScriptDownloadTokenService $tokens,
    ) {}

    /**
     * Reuses an existing active vpn_account for (nas, protocol) if one
     * exists, otherwise provisions a fresh one on the spot. See each
     * branch below for why "reuse" means something different per protocol
     * — only L2TP's password is retrievable after the fact; OpenVPN's key
     * lives on disk (readable again); WireGuard's private key is gone the
     * moment this method returns unless the account was JUST created in
     * this same call.
     */
    public function generateVpnScript(Nas $nas, VpnProtocol $protocol, string $routerOsVersion): string
    {
        if ($protocol === VpnProtocol::WireGuard && $routerOsVersion === '6') {
            throw new VpnScriptGenerationException(
                'WireGuard tidak tersedia di RouterOS 6.x — fitur ini baru ada mulai RouterOS 7.0. Pilih OpenVPN atau L2TP/IPsec, atau upgrade NAS ke RouterOS 7.'
            );
        }

        $account = VpnAccount::query()
            ->where('nas_id', $nas->id)
            ->where('protocol', $protocol)
            ->where('status', VpnAccountStatus::Active)
            ->first();

        $justProvisioned = $account === null;

        if ($account === null) {
            $account = $this->provisioning->provision($nas, $protocol);
        }

        // v0.6.4: public_ip/port now come from the account's OWN assigned
        // node (VpnProvisioningService's load-balanced pick — see
        // VpnServer::poolOwnerFor()'s docblock for why that can be a
        // different node than the one internal_ip/credentials were issued
        // against), not a single global config value — config('services.
        // vpn.openvpn_port'/'wireguard_port') only made sense back when
        // exactly one node per protocol existed.
        $account->loadMissing('vpnServer');
        $node = $account->vpnServer;
        $publicIp = $node->public_ip;
        $freeradiusIp = config('services.vpn.freeradius_internal_ip');

        return match ($protocol) {
            VpnProtocol::OpenVpn => $this->generator->openVpnScript(
                $account,
                $routerOsVersion,
                $publicIp,
                $node->port,
                $freeradiusIp,
                $this->publishFileForDownload(File::get(config('services.vpn.pki_dir').'/ca.crt')),
                $this->publishFileForDownload(File::get(config('services.vpn.pki_dir')."/issued/{$account->username}.crt")),
                $this->publishFileForDownload(File::get(config('services.vpn.pki_dir')."/private/{$account->username}.key")),
                $this->tokens->fetchMode(request()->getSchemeAndHttpHost()),
                $this->onlineNodePorts($protocol),
            ),
            VpnProtocol::WireGuard => $this->wireGuardScriptOrThrow($account, $justProvisioned, $publicIp, $node->port, $freeradiusIp),
            // L2TP/IPsec stays a single node (v0.6.4 scope: known
            // limitation, not part of the multi-node pool) — no
            // auto-switch candidates to offer.
            VpnProtocol::L2tpIpsec => $this->generator->l2tpScript(
                $account,
                $routerOsVersion,
                $publicIp,
                $freeradiusIp,
                config('services.vpn.l2tp_ipsec_psk'),
            ),
        };
    }

    /**
     * v0.6.4 — every ONLINE or FULL node's port for this protocol, fed
     * into MikrotikScriptGenerator's auto-switch scheduler block as
     * failover candidates. FULL nodes are still included deliberately —
     * "no spare capacity for a brand-new account" (VpnProvisioningService's
     * own selection query) is a different question from "can an already-
     * provisioned client's tunnel still reach this node", which shared
     * PKI/peers-dir credentials make possible regardless of current_clients.
     * Only genuinely Offline nodes are excluded.
     */
    private function onlineNodePorts(VpnProtocol $protocol): array
    {
        return VpnServer::query()
            ->where('protocol', $protocol)
            ->where('is_active', true)
            ->whereIn('status', [VpnServerStatus::Online, VpnServerStatus::Full])
            ->pluck('port')
            ->all();
    }

    /**
     * Stores raw file content (certificate/key PEM) behind its own
     * short-lived, single-use download token — same mechanism and same
     * security posture as the top-level script download (see
     * ScriptDownloadTokenService), just one token per file instead of one
     * for a whole script. Used for OpenVPN's 3 files (ca/cert/key); see
     * MikrotikScriptGenerator::openVpnScript()'s docblock for why raw PEM
     * can never go directly into a script body.
     */
    private function publishFileForDownload(string $content): string
    {
        $token = $this->tokens->store(trim($content));

        return $this->tokens->buildDownloadUrl($token, request()->getSchemeAndHttpHost());
    }

    private function wireGuardScriptOrThrow(VpnAccount $account, bool $justProvisioned, string $publicIp, int $port, string $freeradiusIp): string
    {
        if (! $justProvisioned || $account->wireguardPrivateKey === null) {
            throw new VpnScriptGenerationException(
                "NAS '{$account->nas->name}' sudah punya akun WireGuard aktif, tapi private key-nya cuma ditampilkan sekali saat provisioning dan tidak pernah disimpan BOSS App. Cabut (revoke) akun ini dulu, baru generate ulang untuk dapat script yang valid."
            );
        }

        $serverPublicKeyFile = dirname(config('services.vpn.wg_peers_dir')).'/server_public.key';

        return $this->generator->wireGuardScript(
            $account,
            $publicIp,
            $port,
            // trim() matters here, not just tidiness — `wg pubkey` writes a
            // trailing newline, and this value goes straight into a quoted
            // RouterOS string argument (public-key="..."); found via a real
            // generated script where the closing quote landed on its own
            // line right after the key, which real RouterOS hardware has
            // not been confirmed to tolerate.
            trim(File::get($serverPublicKeyFile)),
            $account->wireguardPrivateKey,
            $this->reverseRouteTargets($freeradiusIp, $account->nas),
            $this->onlineNodePorts(VpnProtocol::WireGuard),
            $account->nas->tr069_management_subnet !== null
                ? CidrRange::gatewayAddress($account->vpnServer->subnet_cidr)
                : null,
        );
    }

    /**
     * FreeRADIUS is always reachable through the tunnel; GenieACS NBI/CWMP
     * (v0.7.3) are added only for a NAS that actually has CPE behind it
     * worth issuing a Connection Request to (`tr069_management_subnet` set)
     * — most NAS don't, and there's no reverse route to add for a service
     * with no configured internal IP either (nbi_internal_ip/
     * cwmp_internal_ip stay null until GENIEACS_NBI_INTERNAL_IP/
     * GENIEACS_CWMP_INTERNAL_IP are set — see config/services.php).
     *
     * @return array<string, string> label => IP, fed straight into
     *                               MikrotikScriptGenerator::wireGuardScript()'s $reverseRouteTargets.
     */
    private function reverseRouteTargets(string $freeradiusIp, Nas $nas): array
    {
        $targets = ['freeradius' => $freeradiusIp];

        if ($nas->tr069_management_subnet === null) {
            return $targets;
        }

        foreach ([
            'genieacs-nbi' => config('services.genieacs.nbi_internal_ip'),
            'genieacs-cwmp' => config('services.genieacs.cwmp_internal_ip'),
        ] as $label => $ip) {
            if ($ip !== null) {
                $targets[$label] = $ip;
            }
        }

        return $targets;
    }

    /**
     * v0.6.5: uses this NAS's own dynamic auth_port/acct_port (see
     * MikrotikScriptGenerator::radiusScript()'s own docblock) — no longer
     * FreeRADIUS's shared default port (that was the v0.6.3-v0.6.4 interim
     * state).
     *
     * Deliberately, verifiably READ-ONLY — a real bug (found and fixed
     * before this docblock was written, not a hypothetical) had this
     * method rotate nas.api_username/api_password as a side effect of
     * being called, even for a pure preview that was never applied to the
     * router. Calling this method any number of times in a row must never
     * change anything in the database — see VpnScriptServiceTest's
     * dedicated regression test for this. Mikrotik API user provisioning
     * is a separate, explicit action now — see
     * App\Services\Network\NasApiUserProvisioningService.
     */
    public function generateRadiusScript(Nas $nas): string
    {
        return $this->generator->radiusScript($nas, config('services.vpn.freeradius_internal_ip'));
    }
}
