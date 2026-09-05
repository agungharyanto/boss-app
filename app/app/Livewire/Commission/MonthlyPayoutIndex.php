<?php

namespace App\Livewire\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Models\CommissionLedger;
use App\Services\Commission\CommissionPayoutService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use RuntimeException;

/**
 * v0.9.11 (Payout Komisi) — payout BATCH komisi bulanan (`recurring`/
 * `limited_count`), berbeda total dari payout Titip di `TitipMasukIndex`
 * (yang instan, kapan saja): halaman ini HANYA bisa memproses payout
 * tanggal 5-7 bulan berjalan.
 *
 * Guard jendela tanggal ditegakkan DI `CommissionPayoutService`
 * (`payMonthlyForReferrer()`), BUKAN di komponen ini — komponen ini cuma
 * membaca `isWithinMonthlyPayoutWindow()` untuk keputusan TAMPILAN (banner
 * + sembunyikan/nonaktifkan tombol), supaya panggilan langsung ke method
 * Livewire di luar jendela tetap ditolak oleh service, bukan cuma
 * disembunyikan di UI (sama disiplin "guard di server, bukan cuma UI"
 * yang sudah berkali-kali dipakai di codebase ini — mis. BLOKIR KERAS anti
 * bayar-dobel di `SubscriptionRenewalService`).
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

        try {
            $affected = $payoutService->payMonthlyForReferrer($referrerId, auth()->user());
        } catch (RuntimeException $e) {
            $this->flash = null;
            $this->addError('window', $e->getMessage());

            return;
        }

        $this->resetErrorBag();
        $this->flash = $affected > 0
            ? "{$affected} baris komisi bulanan ditandai dibayar."
            : 'Tidak ada baris komisi yang memenuhi syarat untuk Referrer ini.';
    }

    public function render()
    {
        $payoutService = app(CommissionPayoutService::class);
        $isWithinWindow = $payoutService->isWithinMonthlyPayoutWindow();

        $rows = CommissionLedger::query()
            ->whereIn('scheme', [CommissionScheme::Recurring->value, CommissionScheme::LimitedCount->value])
            ->where('status', CommissionStatus::Eligible->value)
            ->whereNotNull('referrer_id')
            ->with(['referrer:id,name,phone', 'customer:id,name'])
            ->orderByDesc('id')
            ->get();

        $groups = $rows->groupBy('referrer_id')
            ->map(fn ($groupRows) => [
                'referrer' => $groupRows->first()->referrer,
                'rows' => $groupRows,
                'tx_count' => $groupRows->count(),
                'total' => (float) $groupRows->sum(fn ($r) => (float) ($r->amount ?? 0)),
            ])
            ->sortByDesc('total')
            ->values();

        return view('livewire.commission.monthly-payout-index', [
            'groups' => $groups,
            'isWithinWindow' => $isWithinWindow,
            'windowStartDay' => CommissionPayoutService::PAYOUT_WINDOW_START_DAY,
            'windowEndDay' => CommissionPayoutService::PAYOUT_WINDOW_END_DAY,
            'canManage' => auth()->user()->can('markPaid', CommissionLedger::class),
        ]);
    }
}
