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
     *
     * SENGAJA tidak dinormalisasi di sini — `referrers.phone` sudah ada
     * sejak v0.9.2, formatnya apa adanya seperti diketik admin dulu,
     * tidak pernah lewat `WhatsappPhone::normalize()`. Menormalisasi baru
     * di titik lookup ini berisiko membuat baris lama yang formatnya
     * beda-beda jadi tidak ketemu lagi — perilaku method ini TIDAK diubah
     * sama sekali oleh v0.22.2 (lihat `resolvePhoneUser()` di bawah untuk
     * jalur baru yang menormalisasi).
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

    /**
     * v0.22.2 — input non-email (nomor HP), satu titik orkestrasi dipakai
     * bareng Fortify::authenticateUsing() DAN ReferrerLoginController agar
     * urutannya tidak bisa didrift antar caller. Cek `users.phone` (staff)
     * DULU, baru fallback ke `resolveReferrerUser()` (Referrer) kalau
     * tidak ketemu — staff diprioritaskan karena akun staff dikelola admin
     * (StaffService), lebih sedikit dan lebih terpercaya identitasnya
     * dibanding akun Referrer yang self-service.
     *
     * Dinormalisasi via `WhatsappPhone::normalize()` di sisi lookup ini
     * supaya "0812.../+62812.../62812..." semua dianggap nomor yang sama
     * — `StaffService::create()`/`update()` menormalisasi SAAT SIMPAN
     * juga (lihat docblock-nya), jadi kedua sisi konsisten. Fallback ke
     * `resolveReferrerUser($phone)` memakai `$phone` MENTAH (bukan
     * `$normalized`) — perilaku jalur Referrer sengaja tidak diubah.
     */
    public function resolvePhoneUser(string $phone): ?User
    {
        $normalized = WhatsappPhone::normalize($phone);

        $staff = User::where('phone', $normalized)->first();

        if ($staff !== null) {
            return $staff;
        }

        return $this->resolveReferrerUser($phone);
    }
}
