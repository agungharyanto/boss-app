<?php

namespace App\Support;

use App\Models\Referrer;
use App\Models\User;

/**
 * Login terpadu (satu pintu di `/`) — satu field "Email atau Nomor HP".
 * Deteksi jalur + resolusi user, dipakai bareng oleh `Fortify::
 * authenticateUsing()` (POST /login) dan `ReferrerLoginController` (jalur
 * lama `/referrer/login` yang dipertahankan untuk kompatibilitas).
 *
 * Verifikasi password TIDAK dilakukan di sini — pemanggil yang cek
 * (`Hash::check`), supaya kegagalan "user tidak ada" vs "password salah"
 * menghasilkan hasil yang sama (null / false) dan pesan error identik.
 */
class LoginIdentifierResolver
{
    public function isEmail(string $identifier): bool
    {
        return filter_var(trim($identifier), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * User staff dengan email ini (guard web / tabel users). Email unik
     * global, jadi paling banyak satu.
     */
    public function resolveStaffUser(string $email): ?User
    {
        return User::where('email', mb_strtolower(trim($email)))->first();
    }

    /**
     * User yang tertaut ke sebuah Referrer AKTIF dengan nomor HP ini.
     * `Referrer::phone` hanya unik per-tenant; request login adalah guest
     * jadi `BelongsToTenant`'s TenantScope tidak aktif — ini mencari lintas
     * tenant, sama persis pola `ReferrerLoginController` lama (ambil yang
     * pertama by id kalau ada tabrakan lintas tenant, kasus yang tidak
     * terjadi di deployment single-tenant sekarang).
     */
    public function resolveReferrerUser(string $phone): ?User
    {
        $referrer = Referrer::query()
            ->where('phone', trim($phone))
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->first();

        return $referrer !== null ? User::find($referrer->user_id) : null;
    }
}
