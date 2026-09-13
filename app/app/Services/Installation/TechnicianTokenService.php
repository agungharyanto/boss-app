<?php

namespace App\Services\Installation;

use App\Enums\TechnicianStatus;
use App\Exceptions\TechnicianTokenException;
use App\Models\Technician;

/**
 * v0.12.3 — satu sumber kebenaran untuk penerbitan token Sanctum
 * Technician-scoped API, dipakai identik dari `technician:token` (command)
 * dan `POST /technicians/{technician}/token` (endpoint) — bukan logic yang
 * diduplikasi di keduanya.
 *
 * `technicians.user_id` sudah `NOT NULL` di skema (`foreignId('user_id')
 * ->constrained('users')->cascadeOnDelete()`) — user SEHARUSNYA selalu ada.
 * Tetap dicek eksplisit (bukan diasumsikan diam-diam) supaya error-nya
 * jelas kalau race condition aneh (mis. user dihapus lewat jalur lain di
 * antara request) benar-benar terjadi, bukan null-pointer generik.
 */
class TechnicianTokenService
{
    /**
     * Revoke SEMUA token Sanctum lama milik user teknisi ini (bukan cuma
     * token yang berlabel tertentu — "1 teknisi = 1 token aktif" adalah
     * aturan keras, sesuai instruksi), lalu terbitkan SATU token baru.
     * Plaintext token HANYA pernah bisa dilihat sebagai return value
     * method ini — Sanctum sendiri cuma menyimpan hash-nya, sama seperti
     * setiap penerbitan token normal.
     *
     * @throws TechnicianTokenException kalau technician tidak aktif, atau user
     *                                  terkait genuinely tidak ada.
     */
    public function generate(Technician $technician): string
    {
        if ($technician->status !== TechnicianStatus::Active) {
            throw new TechnicianTokenException("Teknisi \"{$technician->name}\" berstatus Nonaktif — token API tidak diterbitkan untuk teknisi nonaktif.");
        }

        $user = $technician->user;

        if ($user === null) {
            throw new TechnicianTokenException("Teknisi \"{$technician->name}\" (#{$technician->id}) tidak punya akun user terkait — tidak bisa menerbitkan token.");
        }

        $user->tokens()->delete();

        return $user->createToken('technician-api')->plainTextToken;
    }
}
