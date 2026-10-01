<?php

namespace App\Support;

use App\Models\Customer;

/**
 * v0.12.2 — satu sumber kebenaran untuk "username RADIUS kandidat milik
 * pelanggan ini", diekstrak dari `RadiusSessionHistoryService`'s private
 * `candidateUsernames()` (v0.8.4) supaya bisa dipakai bareng oleh
 * pembaca `radius_db` lain (mis. `RadiusCredentialService`) tanpa
 * duplikasi logic.
 *
 * `customers.phone_number` adalah username yang ditulis batch migrasi
 * v0.12 untuk mayoritas pelanggan — tapi sebagian kecil (13 dari 551 saat
 * batch pertama dijalankan) punya `legacy_username` yang BEDA dari
 * `phone_number` (batch itu mencocokkan kandidat lewat `phone_number` ATAU
 * `legacy_username`, jadi salah satunya bisa jadi username RADIUS asli
 * untuk mereka). Kedua kandidat dicoba, dedup — bukan menebak salah satu.
 *
 * v0.23.5 — `{$customer->cid}@ppp.bajastu.id` ditambahkan sebagai kandidat
 * ketiga. Skema BARU (aktivasi OMCI, mulai dipakai pertama kali di
 * v0.23.5) menulis username RADIUS berbasis CID + domain suffix, BUKAN
 * phone_number/legacy_username polos seperti skema Track A lama — gap
 * nyata ditemukan saat verifikasi manual: badge "Kredensial PPPoE" di
 * Detail Pelanggan salah menampilkan "Belum di FreeRADIUS" untuk
 * pelanggan yang genuinely sudah punya baris `radcheck`, karena resolver
 * ini belum pernah tahu skema CID sama sekali. `cid` bisa null (belum
 * di-generate) — `array_filter()` di bawah sudah membuang nilai
 * falsy/null, jadi aman tanpa null-check eksplisit di sini.
 */
class RadiusUsernameResolver
{
    /**
     * @return array<int, string>
     */
    public static function candidatesFor(Customer $customer): array
    {
        return array_values(array_unique(array_filter([
            $customer->phone_number,
            $customer->legacy_username,
            $customer->cid ? "{$customer->cid}@ppp.bajastu.id" : null,
        ])));
    }
}
