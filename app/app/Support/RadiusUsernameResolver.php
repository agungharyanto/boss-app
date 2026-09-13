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
        ])));
    }
}
