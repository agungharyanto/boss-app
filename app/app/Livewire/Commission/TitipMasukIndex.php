<?php

namespace App\Livewire\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\TitipDepositStatus;
use App\Models\CommissionLedger;
use App\Services\Commission\CommissionPayoutService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * "Fee Komisi" (label tampilan; route/URL tetap `titip-masuk` supaya
 * bookmark lama tidak rusak) — daftar kerja operasional semua baris
 * `commission_ledger` scheme=titip.
 *
 * Sprint "perpanjang-daftar-pelanggan" (revisi Fee Komisi):
 *  - 2 filter INDEPENDEN (AND): status komisi + status setoran.
 *  - Dikelompokkan per Referrer + kartu ringkasan global.
 *  - CHECKBOX SELEKTIF per baris — admin pilih transaksi SPESIFIK yang
 *    benar-benar sudah disetor (bukan tandai-semua sekaligus): ada kasus
 *    Titip sudah dicatat (layanan diperpanjang) tapi uang cash belum
 *    benar-benar diambil dari pelanggan.
 *  - Aksi: "Tandai Sudah Setor (Terpilih)" (`commission_ledger.manage`).
 *
 * v0.9.11 (Payout Komisi) — "Bayar Komisi Sekarang" (per baris) & "Bayar
 * Semua yang Bisa Dibayar" (per grup Referrer), lewat
 * `CommissionPayoutService::payTitipRow()`/`payTitipForReferrer()`. Instan
 * (tidak ada jendela tanggal, beda dari payout bulanan di
 * `MonthlyPayoutIndex`) TAPI wajib `deposit_status = SudahSetor` — guard
 * ini ditegakkan DI SERVICE (bukan cuma UI menyembunyikan tombol), dan
 * wajib upload 1 foto bukti bayar per transaksi/batch payout.
 *
 * TETAP tanpa approve/reject.
 */
class TitipMasukIndex extends Component
{
    use AuthorizesRequests, WithFileUploads;

    public string $search = '';

    /** '' = semua status komisi */
    public string $statusFilter = '';

    /** '' = semua status setoran */
    public string $depositFilter = '';

    /** id baris commission_ledger yang dicentang untuk ditandai sudah setor */
    public array $selected = [];

    /** id baris commission_ledger yang sedang dibayar lewat modal (null = modal tertutup) */
    public ?int $payingLedgerId = null;

    /** id Referrer yang sedang dibayar SEMUA barisnya lewat modal (null = modal tertutup) */
    public ?int $payingReferrerId = null;

    /** foto bukti bayar yang diunggah admin di modal, sementara sebelum disimpan */
    public $paymentProof = null;

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', CommissionLedger::class);
    }

    public function openPayRowModal(int $ledgerId): void
    {
        $this->authorize('markPaid', CommissionLedger::class);

        $this->payingLedgerId = $ledgerId;
        $this->payingReferrerId = null;
        $this->paymentProof = null;
        $this->resetErrorBag();
    }

    public function openPayReferrerModal(int $referrerId): void
    {
        $this->authorize('markPaid', CommissionLedger::class);

        $this->payingReferrerId = $referrerId;
        $this->payingLedgerId = null;
        $this->paymentProof = null;
        $this->resetErrorBag();
    }

    public function closePayModal(): void
    {
        $this->payingLedgerId = null;
        $this->payingReferrerId = null;
        $this->paymentProof = null;
        $this->resetErrorBag();
    }

    public function confirmPayRow(CommissionPayoutService $payoutService): void
    {
        $this->authorize('markPaid', CommissionLedger::class);

        $this->validate(['paymentProof' => ['required', 'image', 'max:5120']]);

        if ($this->payingLedgerId === null) {
            return;
        }

        $entry = CommissionLedger::query()->find($this->payingLedgerId);

        if ($entry === null) {
            $this->closePayModal();

            return;
        }

        try {
            $payoutService->payTitipRow($entry, auth()->user(), $this->paymentProof);
            $this->flash = 'Komisi berhasil ditandai dibayar.';
        } catch (RuntimeException $e) {
            $this->addError('paymentProof', $e->getMessage());

            return;
        }

        $this->closePayModal();
    }

    public function confirmPayReferrer(CommissionPayoutService $payoutService): void
    {
        $this->authorize('markPaid', CommissionLedger::class);

        $this->validate(['paymentProof' => ['required', 'image', 'max:5120']]);

        if ($this->payingReferrerId === null) {
            return;
        }

        $affected = $payoutService->payTitipForReferrer($this->payingReferrerId, auth()->user(), $this->paymentProof);

        $this->flash = $affected > 0
            ? "{$affected} transaksi titip ditandai dibayar."
            : 'Tidak ada transaksi yang memenuhi syarat untuk dibayar sekarang (harus Layak Dibayar dan Sudah Setor).';

        $this->closePayModal();
    }

    /**
     * Toggle centang untuk SEMUA baris `belum_setor` milik satu Referrer.
     */
    public function toggleGroupSelection(int $referrerId): void
    {
        $ids = $this->belumSetorIdsForReferrer($referrerId);

        $current = array_map('intval', $this->selected);
        $allSelected = $ids !== [] && array_diff($ids, $current) === [];

        $this->selected = $allSelected
            ? array_values(array_diff($current, $ids))
            : array_values(array_unique(array_merge($current, $ids)));
    }

    public function markSelectedDeposited(): void
    {
        $this->authorize('markDeposit', CommissionLedger::class);

        $ids = array_map('intval', $this->selected);

        if ($ids === []) {
            return;
        }

        $affected = CommissionLedger::query()
            ->where('scheme', CommissionScheme::Titip->value)
            ->where('deposit_status', TitipDepositStatus::BelumSetor->value)
            ->whereIn('id', $ids)
            ->update([
                'deposit_status' => TitipDepositStatus::SudahSetor->value,
                'deposited_at' => now(),
                'deposited_by' => auth()->id(),
            ]);

        $this->selected = [];

        $this->flash = $affected > 0
            ? "{$affected} transaksi titip ditandai sudah setor."
            : 'Tidak ada transaksi terpilih yang perlu ditandai.';
    }

    /**
     * @return list<int>
     */
    private function belumSetorIdsForReferrer(int $referrerId): array
    {
        return CommissionLedger::query()
            ->where('scheme', CommissionScheme::Titip->value)
            ->where('referrer_id', $referrerId)
            ->where('deposit_status', TitipDepositStatus::BelumSetor->value)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function render()
    {
        $tenantTitip = fn () => CommissionLedger::query()->where('scheme', CommissionScheme::Titip->value);

        // Kartu ringkasan — GLOBAL (tenant-scoped), independen dari filter di
        // bawah, supaya angka "berapa harus ditagih / dibayar" stabil.
        $totalKomisiHarusDibayar = (float) $tenantTitip()
            ->where('status', CommissionStatus::Eligible->value)
            ->sum('amount');

        $totalSetoranBelumMasuk = (float) $tenantTitip()
            ->where('deposit_status', TitipDepositStatus::BelumSetor->value)
            ->sum('gross_amount');

        $query = $tenantTitip()
            ->with(['customer:id,name', 'referrer:id,name,phone', 'depositedBy:id,name', 'paidBy:id,name'])
            ->orderByDesc('id');

        if ($this->search !== '') {
            $search = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($search) {
                $q->whereHas('customer', fn ($c) => $c->where('name', 'like', $search))
                    ->orWhereHas('referrer', fn ($r) => $r->where('name', 'like', $search));
            });
        }

        // Filter status komisi — independen dari filter setoran (AND).
        if (CommissionStatus::tryFrom($this->statusFilter) !== null) {
            $query->where('status', $this->statusFilter);
        }

        // Filter status setoran.
        if (TitipDepositStatus::tryFrom($this->depositFilter) !== null) {
            $query->where('deposit_status', $this->depositFilter);
        }

        $selectedInt = array_map('intval', $this->selected);

        $isPayable = fn ($r) => $r->status === CommissionStatus::Eligible
            && $r->deposit_status === TitipDepositStatus::SudahSetor;

        $groups = $query->get()
            ->groupBy('referrer_id')
            ->map(function ($groupRows) use ($selectedInt, $isPayable) {
                $belumSetor = $groupRows->where('deposit_status', TitipDepositStatus::BelumSetor);
                $belumSetorIds = $belumSetor->pluck('id')->map(fn ($id) => (int) $id)->all();
                $payableRows = $groupRows->filter($isPayable);

                return [
                    'referrer' => $groupRows->first()->referrer,
                    'rows' => $groupRows,
                    'tx_count' => $groupRows->count(),
                    'total_commission' => (float) $groupRows->sum(fn ($r) => (float) ($r->amount ?? 0)),
                    'total_belum_setor' => (float) $belumSetor->sum(fn ($r) => (float) ($r->gross_amount ?? 0)),
                    'belum_setor_count' => $belumSetor->count(),
                    'all_belum_setor_selected' => $belumSetorIds !== []
                        && array_diff($belumSetorIds, $selectedInt) === [],
                    'payable_count' => $payableRows->count(),
                    'payable_total' => (float) $payableRows->sum(fn ($r) => (float) ($r->amount ?? 0)),
                ];
            })
            ->sortByDesc('total_belum_setor')
            ->values();

        return view('livewire.commission.titip-masuk-index', [
            'groups' => $groups,
            'isPayable' => $isPayable,
            'totalKomisiHarusDibayar' => $totalKomisiHarusDibayar,
            'totalSetoranBelumMasuk' => $totalSetoranBelumMasuk,
            'statuses' => CommissionStatus::cases(),
            'depositStatuses' => TitipDepositStatus::cases(),
            'canManage' => auth()->user()->can('markDeposit', CommissionLedger::class),
            'selectedCount' => count($selectedInt),
        ]);
    }
}
