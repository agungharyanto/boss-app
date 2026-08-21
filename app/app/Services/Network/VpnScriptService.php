<?php

namespace App\Services\Network;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnProtocol;
use App\Enums\VpnServerStatus;
use App\Exceptions\VpnScriptGenerationException;
use App\Models\Nas;
use App\Models\VpnAccount;
use App\Models\VpnServer;
use App\Models\VpnWireguardNasBlock;
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
            // v0.8.1 — a single reserved /27 (INFRA_TUNNEL_BLOCK_CIDR),
            // always included regardless of what this specific NAS
            // actually uses (FreeRADIUS/GenieACS/LibreNMS/future modules
            // all share one fixed block now — see MikrotikScriptGenerator
            // ::wireGuardScript()'s own docblock). The old per-NAS
            // conditional inclusion of GenieACS NBI/CWMP based on
            // tr069_management_subnet is GONE — "which service is allowed
            // to reach which NAS" is now an application-level concern
            // (e.g. tr069_management_subnet still gates whether GenieACS
            // itself ever tries a Connection Request), not a VPN-layer
            // one.
            config('services.vpn.infra_block_cidr'),
            $freeradiusIp,
            $this->onlineNodePorts(VpnProtocol::WireGuard),
            // v0.8.1 — this NAS's OWN dedicated gateway (VpnWireguardNasBlock),
            // replacing the single address shared by every NAS
            // (CidrRange::gatewayAddress($account->vpnServer->subnet_cidr)
            // — that call always returned 172.23.195.1 regardless of
            // which NAS was asking). provision() guarantees a block exists
            // for any account that reaches this point (wireGuardScriptOrThrow()
            // only proceeds when $justProvisioned is true, i.e. provision()
            // just ran), so this is never null in practice — still gated
            // on tr069_management_subnet, same reasoning as before (only
            // needed when this NAS actually uses MASQUERADE-based reverse
            // routing).
            $account->nas->tr069_management_subnet !== null
                ? VpnWireguardNasBlock::where('nas_id', $account->nas_id)->value('gateway_ip')
                : null,
        );
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
