<?php

namespace App\Livewire\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Models\CommissionLedger;
use App\Services\Commission\CommissionPayoutService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * v0.9.11 (Payout Komisi) — payout BATCH komisi bulanan (`recurring`/
 * `limited_count`), berbeda total dari payout Titip di `TitipMasukIndex`
 * (yang instan, kapan saja).
 *
 * AMANDEMEN (2026-09-05) — jendela tanggal BUKAN LAGI satu aturan global
 * (dulu "tanggal 5-7" untuk semua Referrer) — sekarang dikonfigurasi PER
 * `CommissionRate` (per paket, lihat `CommissionRate::payout_window_*`).
 * Konsekuensi: Referrer dengan komisi dari beberapa paket berjendela
 * berbeda punya campuran baris "bisa dibayar sekarang"/"belum" DALAM SATU
 * grup — komponen ini menghitung status itu PER BARIS
 * (`CommissionPayoutService::isRowPayableNow()`), bukan satu flag halaman.
 * Tombol "Proses Payout" per grup selalu memanggil `payMonthlyForReferrer()`
 * yang MEMBAYAR baris yang genuinely payable dan MELEWATI sisanya (sama
 * semantik "bayar yang bisa, skip yang belum" seperti tombol Titip) —
 * bukan lagi ditolak-total kalau ada satu saja di luar jendela.
 *
 * Guard sesungguhnya tetap DI `CommissionPayoutService`, bukan di sini —
 * komponen ini cuma membaca `isRowPayableNow()` untuk keputusan TAMPILAN.
 *
 * Sama permission dengan `TitipMasukIndex`/`CommissionLedgerPolicy` —
 * tidak ada permission baru (`commission_ledger.view`/`.manage`).
 */
class MonthlyPayoutIndex extends Component
{
    use AuthorizesRequests;

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', CommissionLedger::class);
    }

    public function payReferrer(int $referrerId, CommissionPayoutService $payoutService): void
    {
        $this->authorize('markPaid', CommissionLedger::class);

        $affected = $payoutService->payMonthlyForReferrer($referrerId, auth()->user());

        $this->flash = $affected > 0
            ? "{$affected} baris komisi bulanan ditandai dibayar."
            : 'Tidak ada baris komisi yang memenuhi syarat untuk dibayar sekarang (harus Layak Dibayar dan jendela tanggal paketnya sedang terbuka).';
    }

    public function render()
    {
        $payoutService = app(CommissionPayoutService::class);

        $rows = CommissionLedger::query()
            ->whereIn('scheme', [CommissionScheme::Recurring->value, CommissionScheme::LimitedCount->value])
            ->where('status', CommissionStatus::Eligible->value)
            ->whereNotNull('referrer_id')
            ->with(['referrer:id,name,phone', 'customer:id,name,ppp_package_id', 'customer.pppPackage.commissionRate'])
            ->orderByDesc('id')
            ->get();

        $groups = $rows->groupBy('referrer_id')
            ->map(function ($groupRows) use ($payoutService) {
                $withPayable = $groupRows->map(fn (CommissionLedger $row) => [
                    'row' => $row,
                    'payable_now' => $payoutService->isRowPayableNow($row),
                ]);
                $payable = $withPayable->where('payable_now', true);

                return [
                    'referrer' => $groupRows->first()->referrer,
                    'rows' => $withPayable,
                    'tx_count' => $groupRows->count(),
                    'total' => (float) $groupRows->sum(fn ($r) => (float) ($r->amount ?? 0)),
                    'payable_count' => $payable->count(),
                    'payable_total' => (float) $payable->sum(fn ($p) => (float) ($p['row']->amount ?? 0)),
                ];
            })
            ->sortByDesc('total')
            ->values();

        return view('livewire.commission.monthly-payout-index', [
            'groups' => $groups,
            'canManage' => auth()->user()->can('markPaid', CommissionLedger::class),
        ]);
    }
}
