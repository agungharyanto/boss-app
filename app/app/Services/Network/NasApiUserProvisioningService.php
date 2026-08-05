<?php

namespace App\Services\Network;

use App\Exceptions\NasApiUserProvisioningException;
use App\Models\Nas;
use App\Services\Network\Contracts\RouterOsGateway;
use Illuminate\Support\Str;

/**
 * The ONLY place nas.api_username/api_password are ever written — a real
 * bug (VpnScriptService::generateRadiusScript() rotating them as a side
 * effect of mere script preview, regardless of whether the script was ever
 * applied) is the reason this class exists as a separate, deliberate action
 * instead of being folded into script generation. Two distinct credentials
 * are involved and must never be confused:
 *
 * - **Admin credential** ($adminUsername/$adminPassword below) — the NAS
 *   owner's own real, full-access router login. Used exactly once, in
 *   memory, for this one call — NEVER persisted anywhere (not to `nas`, not
 *   logged). Supplied through a form deliberately kept separate from the
 *   NAS edit form's own fields (see CoaDisconnectRequest-style dedicated
 *   Form Request), so it can never accidentally end up bound to
 *   nas.api_username/api_password by a careless refactor.
 * - **API credential** (nas.api_username/api_password) — a dedicated,
 *   restricted-policy (`boss-app-api` group: read+api+password, nothing
 *   else — see RouterOsApiGateway::API_USER_POLICY) user BOSS App fully
 *   owns the lifecycle of. This is what NasService::testConnection() and
 *   every other router-facing read uses day to day.
 *
 * `provision()` covers BOTH the one-time initial creation (admin
 * credential connects, creates the dedicated user) AND later self-rotation
 * (the NAS's OWN current, already-working API credential connects and
 * changes its own password) — same underlying gateway call either way, the
 * caller just decides which credential to pass as $connectAsUsername/
 * $connectAsPassword. The `boss-app-api` group's `password` policy right
 * (confirmed explicitly with Agung before implementing, a deliberately
 * broader-than-pure-read-only tradeoff) is what makes self-rotation
 * possible without ever asking for the admin credential again after the
 * first call.
 */
class NasApiUserProvisioningService
{
    public function __construct(
        private readonly RouterOsGateway $gateway,
    ) {}

    /**
     * Initial provisioning — admin credential in, dedicated API user out.
     * Username convention: `boss-app-api-{nas_id}`, unique per NAS even if
     * two NAS rows somehow point at the same physical router.
     */
    public function provisionWithAdminCredential(Nas $nas, string $adminUsername, string $adminPassword): Nas
    {
        $newUsername = "boss-app-api-{$nas->id}";
        $newPassword = Str::random(24);

        $result = $this->gateway->provisionApiUser($nas, $adminUsername, $adminPassword, $newUsername, $newPassword);

        if (! $result['success']) {
            throw new NasApiUserProvisioningException(
                "Gagal membuat user API di router: {$result['message']}"
            );
        }

        $nas->update(['api_username' => $newUsername, 'api_password' => $newPassword]);

        return $nas->fresh();
    }

    /**
     * Self-rotation — connects using the NAS's OWN currently-stored API
     * credential (must already exist and still be valid) to change its own
     * password, no admin credential needed. Not yet wired into any UI
     * action this sprint (out of scope — see CLAUDE.md v0.6.5), but the
     * `boss-app-api` group's policy already supports it, so this is ready
     * for a future "rotate now" feature without any router-side changes.
     */
    public function rotate(Nas $nas): Nas
    {
        if ($nas->api_username === null || $nas->api_password === null) {
            throw new NasApiUserProvisioningException(
                "NAS '{$nas->name}' belum punya user API — jalankan provisioning awal dengan kredensial admin dulu."
            );
        }

        $newPassword = Str::random(24);

        $result = $this->gateway->provisionApiUser($nas, $nas->api_username, $nas->api_password, $nas->api_username, $newPassword);

        if (! $result['success']) {
            throw new NasApiUserProvisioningException(
                "Gagal merotasi password user API di router: {$result['message']}"
            );
        }

        $nas->update(['api_password' => $newPassword]);

        return $nas->fresh();
    }
}
