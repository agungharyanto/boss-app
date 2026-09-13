<?php

namespace App\Services\Network;

use App\Models\Customer;
use App\Support\RadiusUsernameResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v0.12.6 — "Grafik Pemakaian" pada Detail Perangkat CPE. Reuse pola
 * RadiusSessionHistoryService (v0.8.4) verbatim: koneksi Eloquent 'radius'
 * terpisah (radius_db, BOSS-009), username kandidat via
 * RadiusUsernameResolver::candidatesFor() (phone_number ATAU
 * legacy_username, dedup — bukan menebak salah satu).
 *
 * Agregasi per HARI dari `acctstoptime` (BUKAN `acctstarttime`) — sengaja,
 * karena `Acct-Interim-Interval` TIDAK dikonfigurasi (dikonfirmasi di
 * RadiusSessionHistoryService's own docblock): `acctinputoctets`/
 * `acctoutputoctets` sebuah sesi yang MASIH AKTIF tetap 0 sampai sesi itu
 * genuinely berakhir (Accounting-Stop) — mengagregasi dari `acctstarttime`
 * akan membaca byte yang belum final (0) untuk sesi yang lintas hari.
 * Konsekuensi yang diterima (sama kelas simplifikasi seperti
 * `RadiusSessionHistoryService::sessionSeconds()`): total sebuah sesi
 * lintas-hari (jarang, ISP residential biasanya reconnect harian) masuk
 * SELURUHNYA ke hari sesi itu BERAKHIR, tidak didistribusikan proporsional
 * ke tiap hari yang dilewati — trade-off wajar untuk data yang memang
 * hanya final di titik Accounting-Stop, sama seperti "belum ada data
 * pemakaian" untuk sesi aktif itu sendiri sampai dia selesai.
 */
class CpeUsageHistoryService
{
    /**
     * True kalau username manapun milik customer ini PUNYA row `radacct`
     * sama sekali (tanpa batas tanggal) — dipakai untuk membedakan "belum
     * pernah tercatat lewat FreeRADIUS boss-app SAMA SEKALI" dari "sudah
     * pernah, cuma kebetulan 0 di 30 hari terakhir" (kasus kedua tetap
     * render chart dengan nilai 0, bukan pesan kosong).
     */
    public function hasAnyRecordedUsage(Customer $customer): bool
    {
        $usernames = RadiusUsernameResolver::candidatesFor($customer);

        if ($usernames === []) {
            return false;
        }

        return DB::connection('radius')->table('radacct')->whereIn('username', $usernames)->exists();
    }

    /**
     * Satu baris PER HARI dalam rentang (termasuk hari tanpa sesi selesai
     * sama sekali — diisi 0, bukan dilewati) supaya sumbu X chart tetap
     * kontinu $days hari berurutan.
     *
     * @return array<int, array{date: string, upload_mb: float, download_mb: float}>
     */
    public function dailyUsageForCustomer(Customer $customer, int $days = 30): array
    {
        $usernames = RadiusUsernameResolver::candidatesFor($customer);
        $end = Carbon::today();
        $start = $end->copy()->subDays($days - 1);

        $byDate = [];

        if ($usernames !== []) {
            $rows = DB::connection('radius')
                ->table('radacct')
                ->selectRaw('DATE(acctstoptime) as usage_date, SUM(acctinputoctets) as total_input, SUM(acctoutputoctets) as total_output')
                ->whereIn('username', $usernames)
                ->whereNotNull('acctstoptime')
                ->whereDate('acctstoptime', '>=', $start->toDateString())
                ->whereDate('acctstoptime', '<=', $end->toDateString())
                ->groupBy('usage_date')
                ->get();

            foreach ($rows as $row) {
                $byDate[Carbon::parse($row->usage_date)->toDateString()] = [
                    'upload_mb' => round(((int) $row->total_input) / 1048576, 2),
                    'download_mb' => round(((int) $row->total_output) / 1048576, 2),
                ];
            }
        }

        $series = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $dateKey = $cursor->toDateString();
            $series[] = [
                'date' => $dateKey,
                'upload_mb' => $byDate[$dateKey]['upload_mb'] ?? 0.0,
                'download_mb' => $byDate[$dateKey]['download_mb'] ?? 0.0,
            ];
        }

        return $series;
    }
}
