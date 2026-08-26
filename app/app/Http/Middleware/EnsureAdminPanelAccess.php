<?php

namespace App\Http\Middleware;

use App\Enums\ResellerUserStatus;
use App\Models\ResellerUser;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * v0.9.2 — closes the "no middleware blocks cross-persona access" gap
 * (see CLAUDE.md's own investigation section for v0.9.2). Deliberately
 * checks "has ANY Spatie permission at all (role OR direct grant) OR an
 * active reseller_users membership" rather than hardcoding a role-name
 * list — two real regressions were caught running the full test suite
 * against narrower versions of this check, in order:
 * 1. A roles()-only check locked out users with a permission granted
 *    directly, no role wrapper (e.g. `$viewer->givePermissionTo(
 *    'cpe_devices.view')`, see CpeDeviceDatatableControllerTest/
 *    OltDeviceDatatableControllerTest/CpeDeviceDetailControllerTest/
 *    CpeDeviceShowPageTest) — fixed by checking getAllPermissions()
 *    instead, which Spatie already unions across both sources.
 * 2. A getAllPermissions()-only check still locked out reseller owner/
 *    staff users, who are authorized PURELY via an active reseller_users
 *    membership row and correctly hold ZERO Spatie roles/permissions by
 *    this codebase's own established design (see CLAUDE.md's repeated
 *    "reseller owner/staff diotorisasi lewat keanggotaan reseller_users...
 *    bukan lewat permission Spatie" note across many modules) — confirmed
 *    via OltDeviceDatatableControllerTest's own
 *    test_reseller_only_sees_their_own_olt_devices.
 * A Referrer-portal-only account (see ReferrerService::
 * attachNewLoginAccount()) has none of the three (no role, no direct
 * permission, no reseller_users row), so this broader check is still
 * correct for the actual boundary this middleware exists to enforce.
 * Fine-grained per-page authorization still happens via Policy/permission
 * checks exactly as before — this is only the outer "is this even a staff/
 * reseller account, not a pure Referrer-portal account" gate.
 */
class EnsureAdminPanelAccess
{
    /**
     * Extracted as a reusable, named check (not just inlined in handle())
     * so the root `/` route (see routes/web.php) can branch a logged-in
     * user toward the admin dashboard vs. the Referrer portal using the
     * EXACT same rule this middleware enforces — a route computing its own
     * slightly-different definition of "admin-eligible" would risk drifting
     * out of sync and redirecting a user somewhere this middleware then
     * immediately 403s them out of.
     */
    public static function userHasAccess(User $user): bool
    {
        return $user->getAllPermissions()->isNotEmpty()
            || ResellerUser::where('user_id', $user->id)->where('status', ResellerUserStatus::Active)->exists();
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! self::userHasAccess($user)) {
            throw new HttpException(403, 'Akun ini tidak punya akses ke panel admin.');
        }

        return $next($request);
    }
}
