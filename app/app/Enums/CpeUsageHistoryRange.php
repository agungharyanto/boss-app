<?php

namespace App\Enums;

/**
 * v0.12.6 (revisi) — 4 tab rentang untuk modal "Riwayat" pada Grafik
 * Pemakaian (App\Livewire\Network\CpeUsageHistoryGraph), analog
 * App\Enums\CpeSignalHistoryRange (RX Power) TAPI vocab-nya SENGAJA
 * berbeda, bukan reuse langsung — dijelaskan di
 * CpeUsageHistoryGraph's own docblock:
 *
 * `CpeSignalHistoryRange` punya tab "Jam" (3 jam) karena RX Power
 * disinkronkan tiap ~20 menit (data sub-harian genuinely ada) dan tab
 * Minggu/Bulan/Tahun butuh agregasi SQL (hourly/daily/weekly grain)
 * karena volume baris mentahnya besar (poll tiap 20 menit -> ~26.000
 * baris/tahun). Grafik Pemakaian BEDA SIFAT DATANYA sama sekali: data
 * radacct cuma final SEKALI per sesi (di titik Accounting-Stop, lihat
 * CpeUsageHistoryService's own docblock) — tidak ada satu pun data
 * sub-harian yang genuinely ada untuk direpresentasikan sebagai "per
 * jam", dan SUM per hari (bahkan untuk 365 hari) cuma menghasilkan
 * maksimum 365 baris — jauh dari cukup besar untuk butuh agregasi
 * mingguan/bulanan tambahan. Jadi: SEMUA 4 tab di sini tetap granularitas
 * PER HARI (windowDays() saja, tidak ada aggregationGrain()) — yang
 * berubah HANYA rentang window-nya, bukan tingkat detailnya.
 */
enum CpeUsageHistoryRange: string
{
    case Days30 = '30_days';
    case Days90 = '90_days';
    case Days180 = '180_days';
    case Days365 = '365_days';

    public function label(): string
    {
        return match ($this) {
            self::Days30 => '30 Hari',
            self::Days90 => '3 Bulan',
            self::Days180 => '6 Bulan',
            self::Days365 => '1 Tahun',
        };
    }

    public function windowDays(): int
    {
        return match ($this) {
            self::Days30 => 30,
            self::Days90 => 90,
            self::Days180 => 180,
            self::Days365 => 365,
        };
    }

    public static function default(): self
    {
        return self::Days30;
    }
}
