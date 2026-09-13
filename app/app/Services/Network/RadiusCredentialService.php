<?php

namespace App\Services\Network;

use App\Models\Customer;
use App\Support\RadiusUsernameResolver;
use Illuminate\Support\Facades\DB;

/**
 * v0.12.2 — baca kredensial PPPoE (`radcheck`/`radreply`) pelanggan lewat
 * koneksi `radius` (`radius_db`, BOSS-009 — tidak pernah cross-database
 * join dengan `boss_db`). Dipakai `App\Livewire\Customers\CustomerShow`
 * (section "Kredensial PPPoE") — satu-satunya penulis `radcheck` sejauh
 * ini adalah `RadcheckWriterService` (Track A v0.12.2), method ini adalah
 * PEMBACA pertamanya untuk kebutuhan tampilan per-pelanggan.
 *
 * Username kandidat di-resolve lewat `RadiusUsernameResolver` (sama
 * persis yang dipakai `RadiusSessionHistoryService` untuk `radacct`) —
 * SATU sumber kebenaran untuk "username RADIUS pelanggan ini", bukan
 * logic yang diduplikasi ulang di sini.
 */
class RadiusCredentialService
{
    /**
     * Null kalau pelanggan tidak punya kandidat username sama sekali
     * (tidak ada `phone_number` maupun `legacy_username`) ATAU tidak ada
     * baris `radcheck` untuk kandidat manapun (belum pernah dimigrasi ke
     * RADIUS) — kedua kasus TIDAK dibedakan di sini, sama alasan
     * `RadiusSessionHistoryService`'s own docblock (tidak ada cara
     * membedakan dari `radcheck` semata, dan UI-nya toh menampilkan state
     * "Belum di FreeRADIUS" yang sama untuk keduanya).
     *
     * @return array{username: string, password: ?string, enabled: bool, framed_pool: ?string}|null
     */
    public function lookupForCustomer(Customer $customer): ?array
    {
        $usernames = RadiusUsernameResolver::candidatesFor($customer);

        if ($usernames === []) {
            return null;
        }

        $check = DB::connection('radius')
            ->table('radcheck')
            ->whereIn('username', $usernames)
            ->where('attribute', 'Cleartext-Password')
            ->first();

        if ($check === null) {
            return null;
        }

        $framedPool = DB::connection('radius')
            ->table('radreply')
            ->where('username', $check->username)
            ->where('attribute', 'Framed-Pool')
            ->value('value');

        return [
            'username' => $check->username,
            'password' => $check->value,
            // `radcheck` di codebase ini tidak pernah menulis baris
            // reject/disabled — keberadaan baris Cleartext-Password ITU
            // SENDIRI berarti aktif (lihat RadcheckWriterService). `enabled`
            // tetap dikembalikan eksplisit (bukan cuma "baris ada = aktif"
            // diam-diam) supaya UI punya satu field jelas untuk kondisi ini,
            // dan mudah diperluas kalau mekanisme disable baris ditambahkan
            // nanti.
            'enabled' => true,
            'framed_pool' => $framedPool,
        ];
    }
}
