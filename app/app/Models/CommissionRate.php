<?php

namespace App\Models;

use App\Enums\CommissionScheme;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CommissionRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * v0.9.3 — konfigurasi rate komisi untuk satu PppPackage. Lihat migration
 * `create_commission_rates_table` untuk arti tiap kolom / ketiga skema.
 *
 * v0.9.4 — mulai dikonsumsi: `customers.ppp_package_id` + `commission_ledger.
 * scheme` menautkan atribusi referral ke rate ini, dan
 * `App\Services\CommissionAttributionService` mengisi `commission_ledger.
 * amount` dari `recurring_amount` / `limited_count_amount` sesuai skema yang
 * dipilih. `titip_amount` tetap TIDAK dipakai jalur ini (mekanisme
 * terpisah). Sepenuhnya independen dari `subscriptions`/billing.
 */
class CommissionRate extends Model
{
    /** @use HasFactory<CommissionRateFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'ppp_package_id',
        'recurring_amount',
        'limited_count_amount',
        'limited_count_times',
        'titip_amount',
        'payout_window_start_day',
        'payout_window_end_day',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'recurring_amount' => 'decimal:2',
            'limited_count_amount' => 'decimal:2',
            'limited_count_times' => 'integer',
            'titip_amount' => 'decimal:2',
            'payout_window_start_day' => 'integer',
            'payout_window_end_day' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function pppPackage(): BelongsTo
    {
        return $this->belongsTo(PppPackage::class);
    }

    /**
     * v0.9.4 — opsi "Skema Komisi" *atribusi pelanggan* yang tersedia untuk
     * rate ini, dipakai form registrasi + edit pelanggan (SATU sumber
     * kebenaran — kedua tempat memanggil method ini, tidak ada duplikat
     * format currency). HANYA skema yang amount-nya benar-benar diisi.
     *
     * "Titip" SENGAJA TIDAK termasuk di sini (v0.9.6): Titip bukan skema
     * atribusi yang dipilih admin saat registrasi — ia dicatat per-pembayaran
     * lewat Portal Referrer (self-service + OTP). `titipAmount()` di bawah
     * adalah accessor terpisah untuk jalur itu.
     *
     * Label menyertakan nominal Rupiah (ribuan pakai titik, tanpa desimal)
     * supaya admin tahu persis nilainya sebelum memilih:
     *   'recurring'     => 'Per Bulan - Rp 3.000'
     *   'limited_count' => '2 Kali - Rp 33.000'
     *
     * @return array<string, string> value => label
     */
    public function schemeOptions(): array
    {
        $options = [];

        if ($this->recurring_amount !== null) {
            $options[CommissionScheme::Recurring->value] =
                'Per Bulan - Rp '.self::formatRupiah($this->recurring_amount);
        }

        if ($this->limited_count_amount !== null) {
            $options[CommissionScheme::LimitedCount->value] =
                $this->limited_count_times.' Kali - Rp '.self::formatRupiah($this->limited_count_amount);
        }

        return $options;
    }

    /**
     * v0.9.6 — nominal komisi Titip untuk rate ini, atau null kalau rate ini
     * tidak menyediakannya. Dipakai `App\Services\Commission\
     * ReferrerTitipService` untuk mengisi `commission_ledger.amount` saat
     * Referrer mencatat titip pembayaran. Titip tidak pernah "diketik
     * manual" — selalu dari sini.
     */
    public function titipAmount(): ?float
    {
        return $this->titip_amount !== null ? (float) $this->titip_amount : null;
    }

    /**
     * Format nominal komisi: ribuan pakai titik, tanpa desimal
     * (mis. "3000.00" -> "3.000", "33000.00" -> "33.000").
     */
    private static function formatRupiah(string|float $amount): string
    {
        return number_format((float) $amount, 0, ',', '.');
    }

    /**
     * v0.9.4 — nominal komisi untuk skema tertentu, atau null kalau skema
     * itu tidak tersedia di rate ini (amount-nya null / skema tidak
     * dikenal).
     *
     * v0.9.6 — `titip` ikut di-resolve di sini (dari `titip_amount`) supaya
     * `match`-nya lengkap dan `ReferrerTitipService` bisa memakainya. TAPI
     * `CommissionLedgerMaturityService` (jalur invoice-lunas) tidak pernah
     * memanggilnya dengan `'titip'` — baris scheme=titip dikecualikan dari
     * lookup template di service itu.
     */
    public function amountForScheme(?string $scheme): ?float
    {
        return match ($scheme) {
            CommissionScheme::Recurring->value => $this->recurring_amount !== null ? (float) $this->recurring_amount : null,
            CommissionScheme::LimitedCount->value => $this->limited_count_amount !== null ? (float) $this->limited_count_amount : null,
            CommissionScheme::Titip->value => $this->titipAmount(),
            default => null,
        };
    }

    /**
     * v0.9.11 lanjutan — jendela tanggal payout komisi BULANAN
     * (recurring/limited_count) untuk rate ini. NULL/NULL (default) =
     * tidak dibatasi, bisa dibayar kapan saja — konsisten dengan
     * `App\Services\Commission\CommissionPayoutService::isRowPayableNow()`,
     * yang membaca kedua kolom ini per baris `commission_ledger`, bukan
     * satu aturan tanggal global untuk semua rate.
     */
    public function hasPayoutWindow(): bool
    {
        return $this->payout_window_start_day !== null && $this->payout_window_end_day !== null;
    }

    public function isWithinPayoutWindow(?Carbon $now = null): bool
    {
        if (! $this->hasPayoutWindow()) {
            return true;
        }

        $day = ($now ?? now())->day;

        return $day >= $this->payout_window_start_day && $day <= $this->payout_window_end_day;
    }

    /**
     * v0.9.11 lanjutan — kedua kolom jendela tanggal HARUS pasangan
     * (keduanya terisi atau keduanya kosong, tidak boleh cuma salah satu),
     * dan `end >= start`. Dipakai bareng oleh Store/UpdateCommissionRateRequest
     * dan `CommissionRateIndex` — sama disiplin "satu sumber kebenaran
     * untuk aturan lintas-field" seperti `schemeErrors()` di atas.
     *
     * @return array<string, string>
     */
    public static function payoutWindowErrors(mixed $start, mixed $end): array
    {
        $set = static fn (mixed $v): bool => $v !== null && $v !== '';

        $errors = [];

        if ($set($start) && ! $set($end)) {
            $errors['payout_window_end_day'] = 'Sampai Tanggal wajib diisi jika Dari Tanggal diisi.';
        } elseif ($set($end) && ! $set($start)) {
            $errors['payout_window_start_day'] = 'Dari Tanggal wajib diisi jika Sampai Tanggal diisi.';
        } elseif ($set($start) && $set($end) && (int) $end < (int) $start) {
            $errors['payout_window_end_day'] = 'Sampai Tanggal tidak boleh sebelum Dari Tanggal.';
        }

        return $errors;
    }

    /**
     * Aturan validasi lintas-field yang dipakai bersama oleh
     * StoreCommissionRateRequest, UpdateCommissionRateRequest, dan
     * App\Livewire\Commission\CommissionRateIndex (aturan per-field —
     * "numeric >= 0" untuk amount, "integer >= 1" untuk times — tetap
     * aturan biasa, hanya 2 aturan yang menjangkau beberapa field yang
     * tinggal di sini):
     *  1. limited_count_amount & limited_count_times = pasangan (keduanya
     *     terisi atau keduanya kosong).
     *  2. minimal 1 dari recurring_amount / limited_count_amount /
     *     titip_amount harus terisi — tidak boleh submit rate kosong semua.
     *
     * "terisi" = bukan null DAN bukan string kosong (angka 0 yang eksplisit
     * TETAP dianggap terisi — nominal komisi 0 yang disengaja itu sah).
     *
     * Mengembalikan [canonical_snake_key => pesan]; array kosong = valid.
     * Pemanggil memetakan key snake_case ke nama field masing-masing.
     *
     * @return array<string, string>
     */
    public static function schemeErrors(mixed $recurring, mixed $limitedAmount, mixed $limitedTimes, mixed $titip): array
    {
        $set = static fn (mixed $v): bool => $v !== null && $v !== '';

        $errors = [];

        if ($set($limitedAmount) && ! $set($limitedTimes)) {
            $errors['limited_count_times'] = 'Jumlah Kali Pembayaran wajib diisi jika Komisi Skema X-Kali diisi.';
        }

        if ($set($limitedTimes) && ! $set($limitedAmount)) {
            $errors['limited_count_amount'] = 'Komisi Skema X-Kali wajib diisi jika Jumlah Kali Pembayaran diisi.';
        }

        if (! $set($recurring) && ! $set($limitedAmount) && ! $set($titip)) {
            $errors['recurring_amount'] = 'Minimal salah satu skema komisi (Per Bulan, Skema X-Kali, atau Titip) harus diisi.';
        }

        return $errors;
    }
}
