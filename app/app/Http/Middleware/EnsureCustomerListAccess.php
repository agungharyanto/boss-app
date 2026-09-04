<?php

namespace App\Http\Middleware;

use App\Models\Referrer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sprint "perpanjang-daftar-pelanggan" — the Daftar Pelanggan page
 * (customers.index ONLY) is the one admin route a pure Referrer account may
 * reach, so it can record renewals for any customer of its tenant. Every
 * OTHER admin route stays behind `admin.panel` exactly as before.
 *
 * Passes if EITHER:
 *  - the user has admin-panel access (EnsureAdminPanelAccess::userHasAccess
 *    — any Spatie permission, or an active reseller_users membership), OR
 *  - the user is linked to an active Referrer row (referrers.user_id =
 *    auth id, is_active = true).
 *
 * When the user is a Referrer WITHOUT admin-panel access, the resolved
 * Referrer is stashed on the request (`referrer` attribute, same key
 * EnsureReferrerPortalAccess uses) and a `customer_list_referrer_only`
 * flag is set so CustomerIndex can render the stripped-down view (no
 * sidebar, no create/register buttons, no Detail links).
 */
class EnsureCustomerListAccess
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new HttpException(403, 'Perlu login untuk melihat daftar pelanggan.');
        }

        if (EnsureAdminPanelAccess::userHasAccess($user)) {
            return $next($request);
        }

        $referrer = Referrer::where('user_id', $user->id)->where('is_active', true)->first();

        if ($referrer === null) {
            throw new HttpException(403, 'Akun ini tidak punya akses ke daftar pelanggan.');
        }

        $request->attributes->set('referrer', $referrer);
        $request->attributes->set('customer_list_referrer_only', true);

        return $next($request);
    }
}
