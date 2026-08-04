<?php

namespace App\Livewire\Network;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnProtocol;
use App\Exceptions\VpnScriptGenerationException;
use App\Models\Nas;
use App\Models\VpnAccount;
use App\Services\Network\ScriptDownloadTokenService;
use App\Services\Network\VpnProvisioningService;
use App\Services\Network\VpnScriptService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Two tabs — VPN Script (per-protocol client config) and RADIUS Script
 * (v0.6.3 decision B: uses FreeRADIUS's default port, not nas.auth_port/
 * acct_port, until the dynamic virtual server ships in v0.6.5). Reseller-
 * scoped via NasPolicy, same as every other NAS-management surface.
 */
class VpnScriptGenerator extends Component
{
    use AuthorizesRequests;

    public string $tab = 'vpn';

    public ?int $selectedNasId = null;

    public string $routerOsVersion = '7';

    public string $vpnProtocol = 'openvpn';

    public ?string $generatedScript = null;

    /**
     * The short one-liner (/tool fetch + /import) shown as the primary
     * thing to paste into RouterOS — see ScriptDownloadTokenService's
     * docblock for why: pasting a long script (esp. OpenVPN's, which embeds
     * a PEM private key) directly into the router's interactive terminal
     * was found (real hardware testing) to sometimes trigger an unrelated
     * confirmation prompt mid-paste, leaving the rest of the script never
     * executed. $generatedScript is kept alongside it purely so the admin
     * can still see/audit what the fetched script actually contains.
     */
    public ?string $fetchCommand = null;

    public int $downloadTtlMinutes = 0;

    public ?string $errorMessage = null;

    /**
     * True when generation was blocked by an existing active vpn_account
     * for the selected NAS+protocol AND the UI has a real recovery action
     * for that (see revokeAndRegenerate()) — e.g. NOT true for the
     * "WireGuard needs RouterOS 7" error, which revoking anything wouldn't
     * fix.
     */
    public bool $canRevokeAndRegenerate = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Nas::class);
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->generatedScript = null;
        $this->fetchCommand = null;
        $this->errorMessage = null;
        $this->canRevokeAndRegenerate = false;
    }

    public function generateVpn(VpnScriptService $service, ScriptDownloadTokenService $tokens): void
    {
        $this->generatedScript = null;
        $this->fetchCommand = null;
        $this->errorMessage = null;
        $this->canRevokeAndRegenerate = false;

        $this->validate([
            'selectedNasId' => ['required', 'integer'],
            'routerOsVersion' => ['required', 'in:6,7'],
            'vpnProtocol' => ['required', 'in:openvpn,wireguard,l2tp_ipsec'],
        ]);

        $nas = Nas::findOrFail($this->selectedNasId);
        $this->authorize('manage', $nas);

        try {
            $script = $service->generateVpnScript($nas, VpnProtocol::from($this->vpnProtocol), $this->routerOsVersion);
            $this->publishDownloadableScript($script, $tokens);
        } catch (VpnScriptGenerationException $e) {
            $this->errorMessage = $e->getMessage();
            $this->canRevokeAndRegenerate = $this->hasActiveAccountForSelectedProtocol($nas);
        }
    }

    /**
     * One-click recovery for the "already has an active account, can't
     * regenerate" case (currently only reachable for WireGuard, whose
     * private key is never stored — see VpnScriptService). Revokes the old
     * account, then immediately re-provisions and generates in the same
     * request. Destructive (old private key/PPP session gone for good, the
     * real NAS's current tunnel — if it ever connected — drops) — the view
     * gates this behind a wire:confirm dialog, not just button visibility.
     */
    public function revokeAndRegenerate(VpnScriptService $service, VpnProvisioningService $provisioning, ScriptDownloadTokenService $tokens): void
    {
        $this->generatedScript = null;
        $this->fetchCommand = null;
        $this->errorMessage = null;

        $nas = Nas::findOrFail($this->selectedNasId);
        $this->authorize('manage', $nas);

        $protocol = VpnProtocol::from($this->vpnProtocol);

        $existing = VpnAccount::query()
            ->where('nas_id', $nas->id)
            ->where('protocol', $protocol)
            ->where('status', VpnAccountStatus::Active)
            ->first();

        if ($existing !== null) {
            $provisioning->revoke($existing);
        }

        try {
            $script = $service->generateVpnScript($nas, $protocol, $this->routerOsVersion);
            $this->publishDownloadableScript($script, $tokens);
        } catch (VpnScriptGenerationException $e) {
            $this->errorMessage = $e->getMessage();
        }

        $this->canRevokeAndRegenerate = false;
    }

    public function generateRadius(VpnScriptService $service, ScriptDownloadTokenService $tokens): void
    {
        $this->generatedScript = null;
        $this->fetchCommand = null;
        $this->errorMessage = null;
        $this->canRevokeAndRegenerate = false;

        $this->validate(['selectedNasId' => ['required', 'integer']]);

        $nas = Nas::findOrFail($this->selectedNasId);
        $this->authorize('manage', $nas);

        $script = $service->generateRadiusScript($nas);
        $this->publishDownloadableScript($script, $tokens);
    }

    /**
     * Shared by all 3 generation entry points (VPN generate, VPN
     * revoke-and-regenerate, RADIUS generate) — every script type goes
     * through the same fetch+import wrapping, not just OpenVPN, for UX
     * consistency across the whole Script Generator page.
     */
    private function publishDownloadableScript(string $script, ScriptDownloadTokenService $tokens): void
    {
        $this->generatedScript = $script;
        $token = $tokens->store($script);
        $this->fetchCommand = $tokens->buildFetchCommand($token, request()->getSchemeAndHttpHost());
        $this->downloadTtlMinutes = $tokens->ttlMinutes();
    }

    public function render()
    {
        return view('livewire.network.vpn-script-generator', [
            'nasList' => Nas::query()->with('reseller')->orderBy('name')->limit(200)->get(),
        ]);
    }

    private function hasActiveAccountForSelectedProtocol(Nas $nas): bool
    {
        return VpnAccount::query()
            ->where('nas_id', $nas->id)
            ->where('protocol', $this->vpnProtocol)
            ->where('status', VpnAccountStatus::Active)
            ->exists();
    }
}
