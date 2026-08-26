<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReferrerLoginRequest;
use App\Models\Referrer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Deliberately separate from Fortify's own /login (which is hard-wired to
 * email as the username field — see FortifyServiceProvider/config/
 * fortify.php's 'username' => 'email') — a Referrer logs in with phone +
 * password instead. Same 'web' guard/session as the admin panel, same
 * pattern already established by reseller_users (no separate guard exists
 * anywhere in this codebase — see CLAUDE.md's own v0.9.2 investigation
 * section for why this is the deliberate, consistent choice, not an
 * oversight).
 */
class ReferrerLoginController extends Controller
{
    public function show(): View
    {
        return view('auth.referrer-login');
    }

    public function login(ReferrerLoginRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Referrer::phone is only unique WITHIN a tenant (tenant_id+phone
        // composite, see the referrers table migration) — there is no
        // "which tenant" selector on this login form. A guest request here
        // is naturally NOT filtered by BelongsToTenant's TenantScope (it
        // only applies when Auth::check() is true), so this searches every
        // tenant already — correct by construction for this deployment's
        // documented single-tenant-per-instance reality. If a future
        // multi-tenant SaaS deployment ever has two tenants sharing the
        // same phone digit string, this picks the first (by id) and logs a
        // warning — same defensive "pick first + log" posture already
        // established by ResolveResellerContext for its own 2+-membership
        // case, not a silent wrong answer.
        $candidates = Referrer::query()->where('phone', $data['phone'])->orderBy('id')->get();

        if ($candidates->count() > 1) {
            Log::warning('Multiple Referrer rows share the same phone number across tenants during a portal login attempt.', [
                'phone' => $data['phone'],
            ]);
        }

        $referrer = $candidates->first();

        if ($referrer === null || ! $referrer->is_active || $referrer->user_id === null) {
            throw ValidationException::withMessages(['phone' => 'Nomor HP atau password salah.']);
        }

        $user = User::find($referrer->user_id);

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['phone' => 'Nomor HP atau password salah.']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('web.referrer-portal.dashboard');
    }

    /**
     * Deliberately NOT Fortify's shared POST /logout — that route's
     * LogoutResponse always redirects to a single global target ('/', by
     * Fortify's own default with no override registered in this codebase),
     * which would land ANY logged-out user — admin or Referrer — back
     * through the same root route, and a logged-out request has no
     * authenticated user left to branch that redirect on. This mirrors
     * Fortify's own AuthenticatedSessionController::destroy() mechanics
     * exactly (guard logout + session invalidate/regenerate token) but
     * redirects explicitly to /referrer/login instead.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('referrer.login');
    }
}
