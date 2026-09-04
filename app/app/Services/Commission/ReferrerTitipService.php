<?php

namespace App\Services\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Models\CommissionLedger;
use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\Referrer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * v0.9.6 — Fitur Titip. Referrer mencatat sendiri bahwa seorang pelanggan
 * membayar cash "titip" ke dia; ia dapat komisi Titip
 * (`CommissionRate.titip_amount`).
 *
 * Batasan (dikonfirmasi Agung):
 *  - Tersedia untuk pelanggan MANA PUN (tenant yang sama) yang: (a) punya
 *    `ppp_package_id`, (b) paket itu punya `CommissionRate` aktif dengan
 *    `titip_amount` terisi. **Syarat "direferensikan Referrer ini" DIHAPUS
 *    (perluasan v0.9.6)** — cash titip bisa dikumpulkan Sales/Teknisi/Agent
 *    mana pun, tidak harus Referrer resmi pelanggan itu. Komisi diatribusi
 *    ke Referrer yang MENCATAT (acting), lewat `record($referrer, ...)`.
 *  - Nominal SELALU dari `CommissionRate.titip_amount` — tidak pernah
 *    diketik manual.
 *  - Baris `commission_ledger` langsung `status = Eligible` (OTP WhatsApp
 *    ke Referrer = jaring pengaman, bukan approval admin).
 *  - CREATE-ONLY: Referrer tidak bisa edit/hapus baris Titip-nya sendiri —
 *    itu ranah admin lewat entri adjustment baru (lihat CLAUDE.md).
 *  - Multi-titip per pelanggan boleh (1 per bulan). `existingForMonth()`
 *    dipakai UI sebagai PERINGATAN (bukan hard block) sebelum submit.
 *  - TIDAK ada otomasi apa pun ke NAS/RADIUS/MixRadius — perpanjangan
 *    layanan tetap proses manual admin di luar BOSS App.
 *
 * Tenant-eksplisit (tidak bergantung Auth) supaya aman dari jalur mana pun.
 */
class ReferrerTitipService
{
    /**
     * v0.9.6 (perluasan) — Titip tersedia untuk pelanggan MANA PUN yang
     * punya `ppp_package_id` + `CommissionRate` aktif dengan `titip_amount`.
     * Syarat "direferensikan Referrer ini" DIHAPUS (keputusan Agung): cash
     * titip bisa dikumpulkan siapa saja, tidak harus Referrer resmi.
     *
     * @return array{available: bool, reason: ?string, amount: ?float, package_name: ?string}
     */
    public function availabilityFor(Customer $customer): array
    {
        $deny = fn (string $reason): array => [
            'available' => false, 'reason' => $reason, 'amount' => null, 'package_name' => null,
        ];

        if ($customer->ppp_package_id === null) {
            return $deny('Belum tersedia untuk pelanggan ini (paket PPP belum diatur admin).');
        }

        $rate = CommissionRate::withoutGlobalScopes()
            ->where('ppp_package_id', $customer->ppp_package_id)
            ->where('is_active', true)
            ->first();

        $amount = $rate?->titipAmount();

        if ($amount === null) {
            return $deny('Belum tersedia untuk pelanggan ini (rate komisi Titip belum diatur admin).');
        }

        return [
            'available' => true,
            'reason' => null,
            'amount' => $amount,
            'package_name' => $customer->pppPackage?->name,
        ];
    }

    /**
     * Baris Titip yang sudah tercatat untuk `$customer` di bulan `$period`
     * — oleh SIAPA PUN (bukan cuma acting referrer). Dipakai UI sebagai
     * peringatan duplikat: satu pelanggan biasanya bayar cash sekali per
     * bulan, jadi kalau sudah ada catatan (Referrer lain sekalipun),
     * tampilkan peringatan. BUKAN hard block — admin/kasus sah tetap bisa
     * override (mis. cicilan).
     */
    public function existingForMonth(Customer $customer, ?Carbon $period = null): ?CommissionLedger
    {
        $period ??= Carbon::now();

        return CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('scheme', CommissionScheme::Titip->value)
            ->whereDate('payment_period', $period->copy()->startOfMonth()->toDateString())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Catat titip. Pemanggil WAJIB sudah memverifikasi OTP lebih dulu
     * (ReferrerActionOtpService::verify()). Guard duplikat bulan berjalan
     * ADALAH ranah UI (peringatan bisa di-override) — service ini tetap
     * membuat baris kalau dipanggil.
     *
     * @throws \RuntimeException kalau Titip tidak (lagi) tersedia untuk pelanggan ini
     */
    public function record(Referrer $referrer, Customer $customer): CommissionLedger
    {
        $availability = $this->availabilityFor($customer);

        if (! $availability['available']) {
            throw new \RuntimeException($availability['reason'] ?? 'Titip tidak tersedia untuk pelanggan ini.');
        }

        $now = CarbonImmutable::now();
        $period = $now->startOfMonth()->toDateString();

        return CommissionLedger::create([
            'tenant_id' => $customer->tenant_id,
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'invoice_id' => null,
            'scheme' => CommissionScheme::Titip->value,
            'payment_period' => $period,
            'amount' => $availability['amount'],
            'status' => CommissionStatus::Eligible,
            'notes' => "v0.9.6: titip dicatat Referrer via Portal (OTP WhatsApp terverifikasi) {$now->toDateTimeString()}.",
        ]);
    }
}
