<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Login terpadu — SATU redirect pasca-login untuk kedua jalur (email staff
 * & nomor HP Referrer). Tidak menentukan tujuan sendiri: kembalikan ke
 * root `/` dan biarkan route `/` yang sudah ada memutuskan (admin-eligible
 * → `/dashboard`, Referrer murni → `/referrer-portal`, fallback aman) —
 * SATU sumber kebenaran, lihat `EnsureAdminPanelAccess::userHasAccess()`.
 *
 * `->intended('/')` tetap menghormati deep-link yang memicu redirect ke
 * login (misal user klik link `/invoices` saat belum login).
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        return redirect()->intended('/');
    }
}
