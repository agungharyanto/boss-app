<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReferrerLoginRequest;
use App\Support\LoginIdentifierResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * v0.9.2 — dulu halaman login terpisah untuk Referrer (HP + password).
 *
 * v0.22.x (login terpadu) — digabung ke SATU pintu di `/login` (field
 * "Email atau Nomor HP", lihat FortifyServiceProvider::authenticateUsing()).
 * Controller ini DIPERTAHANKAN hanya untuk kompatibilitas link/bookmark
 * lama `/referrer/login`:
 *  - `show()`  → redirect 302 ke `/login` (form terpadu).
 *  - `login()` → tetap berfungsi (reuse LoginIdentifierResolver yang sama),
 *    supaya form lama yang mungkin masih di-cache/di-bookmark & mem-POST ke
 *    sini tidak rusak. Pesan gagal & redirect sukses identik dengan jalur
 *    Fortify.
 *  - `logout()` → tidak berubah (redirect eksplisit ke halaman login).
 */
class ReferrerLoginController extends Controller
{
    public function show(): RedirectResponse
    {
        return redirect()->route('login');
    }

    public function login(ReferrerLoginRequest $request, LoginIdentifierResolver $resolver): RedirectResponse
    {
        $data = $request->validated();

        $user = $resolver->isEmail($data['phone'])
            ? $resolver->resolveStaffUser($data['phone'])
            : $resolver->resolveReferrerUser($data['phone']);

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['phone' => __('auth.failed')]);
        }

        Auth::guard('web')->login($user, (bool) $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    /**
     * Deliberately NOT Fortify's shared POST /logout — that route's
     * LogoutResponse always redirects to a single global target ('/', by
     * Fortify's own default with no override registered in this codebase),
     * which a logged-out request has no authenticated user left to branch
     * on. This mirrors Fortify's own AuthenticatedSessionController::destroy()
     * mechanics exactly (guard logout + session invalidate/regenerate token)
     * but redirects explicitly to /login instead.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('login');
    }
}
