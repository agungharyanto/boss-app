<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CommissionRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * v0.9.3 — konfigurasi rate komisi untuk satu PppPackage. Lihat migration
 * `create_commission_rates_table` untuk arti tiap kolom / ketiga skema.
 *
 * Belum dikonsumsi oleh perhitungan komisi per pelanggan mana pun —
 * `commission_ledger.amount` masih null saat registrasi (lihat
 * RegistrationService). Menghubungkan pelanggan/langganan ke satu
 * `ppp_package_id` (sehingga rate ini bisa dipakai menghitung `amount`)
 * adalah pekerjaan inti v0.9.4, bukan scope v0.9.3 — lihat CLAUDE.md.
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
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'recurring_amount' => 'decimal:2',
            'limited_count_amount' => 'decimal:2',
            'limited_count_times' => 'integer',
            'titip_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function pppPackage(): BelongsTo
    {
        return $this->belongsTo(PppPackage::class);
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
