<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v0.22.6 — memaksa staff yang `must_change_password === true`
 * (password-nya random-generated, lihat StaffService::create()) langsung
 * diarahkan ke halaman ganti password, sebelum bisa membuka halaman admin
 * lain mana pun. Dipasang HANYA di grup route `['auth', 'admin.panel']`
 * (routes/web.php) — TIDAK di grup `customers.list` (jalur akses Referrer
 * murni ke /customers) atau `referrer.portal` (Portal Referrer terpisah),
 * supaya toggle ini tidak pernah nyantol ke jalur Referrer sama sekali.
 *
 * Skip untuk request yang menuju halaman ganti password itu sendiri (via
 * nama route, bukan URL string — lebih tahan kalau URL berubah nanti) —
 * kalau tidak, redirect loop tak berujung.
 */
class EnsurePasswordChanged
{
    public const CHANGE_PASSWORD_ROUTE = 'web.password.change';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs(self::CHANGE_PASSWORD_ROUTE)) {
            return $next($request);
        }

        return redirect()->route(self::CHANGE_PASSWORD_ROUTE);
    }
}
