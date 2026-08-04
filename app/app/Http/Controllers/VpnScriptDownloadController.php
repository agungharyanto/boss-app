<?php

namespace App\Http\Controllers;

use App\Services\Network\ScriptDownloadTokenService;
use Illuminate\Http\Response;

/**
 * Deliberately unauthenticated (no auth:sanctum/session middleware) — this
 * is fetched by RouterOS's `/tool fetch`, which sends neither a session
 * cookie nor an API token. Security comes from the token itself instead:
 * see ScriptDownloadTokenService for why this is safe to leave open.
 */
class VpnScriptDownloadController extends Controller
{
    public function show(string $token, ScriptDownloadTokenService $tokens): Response
    {
        $token = str($token)->before('.rsc')->value();

        $script = $tokens->retrieveAndInvalidate($token);

        abort_if($script === null, 404, 'Link download sudah kedaluwarsa atau sudah pernah dipakai. Generate ulang script dari BOSS App.');

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="boss-vpn-setup.rsc"',
        ]);
    }
}
