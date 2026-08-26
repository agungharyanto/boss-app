<?php

namespace App\Http\Middleware;

use App\Models\Referrer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * v0.9.2 — mirror of EnsureAdminPanelAccess for the other direction: only a
 * User with an active, linked Referrer row (referrers.user_id = auth id)
 * may reach the Referrer portal — an admin/staff user who happens to be
 * logged in has no Referrer record and is refused here, same as a
 * Referrer-portal user is refused by EnsureAdminPanelAccess on the admin
 * side. Resolves the Referrer row once and stashes it on the request
 * (`referrer` attribute) so the portal controller/Livewire components don't
 * need to re-query it.
 */
class EnsureReferrerPortalAccess
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $referrer = $user !== null
            ? Referrer::where('user_id', $user->id)->where('is_active', true)->first()
            : null;

        if ($referrer === null) {
            throw new HttpException(403, 'Akun ini tidak terhubung ke Referrer aktif manapun.');
        }

        $request->attributes->set('referrer', $referrer);

        return $next($request);
    }
}
