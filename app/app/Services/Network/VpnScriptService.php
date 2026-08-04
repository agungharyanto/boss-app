<?php

namespace App\Services\Network;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnProtocol;
use App\Exceptions\VpnScriptGenerationException;
use App\Models\Nas;
use App\Models\VpnAccount;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

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

        $publicIp = config('services.vpn.public_ip');
        $freeradiusIp = config('services.vpn.freeradius_internal_ip');

        return match ($protocol) {
            VpnProtocol::OpenVpn => $this->generator->openVpnScript(
                $account,
                $routerOsVersion,
                $publicIp,
                config('services.vpn.openvpn_port'),
                $freeradiusIp,
                $this->publishFileForDownload(File::get(config('services.vpn.pki_dir').'/ca.crt')),
                $this->publishFileForDownload(File::get(config('services.vpn.pki_dir')."/issued/{$account->username}.crt")),
                $this->publishFileForDownload(File::get(config('services.vpn.pki_dir')."/private/{$account->username}.key")),
                $this->tokens->fetchMode(request()->getSchemeAndHttpHost()),
            ),
            VpnProtocol::WireGuard => $this->wireGuardScriptOrThrow($account, $justProvisioned, $publicIp, $freeradiusIp),
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

    private function wireGuardScriptOrThrow(VpnAccount $account, bool $justProvisioned, string $publicIp, string $freeradiusIp): string
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
            config('services.vpn.wireguard_port'),
            $freeradiusIp,
            File::get($serverPublicKeyFile),
            $account->wireguardPrivateKey,
        );
    }

    /**
     * v0.6.3 decision B: uses FreeRADIUS's default port (see
     * MikrotikScriptGenerator::radiusScript()'s own docblock). Also
     * (re)generates the NAS's Mikrotik API credentials and persists them
     * onto nas.api_username/api_password — closing the loop with
     * NasService::testConnection() (v0.6.1), which needs real, currently-
     * valid credentials to actually succeed. Every call to this method
     * rotates the password (the old one becomes invalid the moment the
     * generated script is run on the router).
     */
    public function generateRadiusScript(Nas $nas): string
    {
        $apiUsername = $nas->api_username ?: 'boss-api';
        $apiPassword = Str::random(20);

        $nas->update(['api_username' => $apiUsername, 'api_password' => $apiPassword]);

        return $this->generator->radiusScript(
            $nas,
            config('services.vpn.freeradius_internal_ip'),
            $apiUsername,
            $apiPassword,
        );
    }
}
