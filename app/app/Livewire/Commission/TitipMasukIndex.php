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
 * BAGIAN D (v0.9.12) — halaman ini TIDAK LAGI khusus Titip. Sekarang
 * menampilkan SEMUA jenis komisi (Titip + Bulanan recurring/limited_count)
 * dalam satu tempat, dengan filter "Jenis Komisi" (Semua/Titip/Bulanan).
 * Kolom yang cuma relevan Titip (Uang Diterima, Status Setoran, checkbox
 * setor) kosong/disembunyikan untuk baris Bulanan. Pembayaran komisi
 * Bulanan bisa langsung dari sini lewat `payMonthlyRow()`/
 * `payMonthlyForReferrer()` TAPI hanya untuk baris yang jendela payout
 * paketnya sedang terbuka (`CommissionPayoutService::isRowPayableNow()`) —
 * halaman "Payout Bulanan" (`MonthlyPayoutIndex`) tetap ada untuk alur
 * batch khusus bulanan. Ringkasan di atas dipisah per jenis + total gabungan.
 * Baris `scheme = NULL` (template v0.9.4 yang belum matang) TIDAK pernah
 * ditampilkan di sini — belum jadi komisi nyata.
 *
 * TETAP tanpa approve/reject.
 */
class TitipMasukIndex extends Component
{
    use AuthorizesRequests, WithFileUploads;

    public string $search = '';

    /** '' = semua jenis, 'titip', 'bulanan' (recurring + limited_count) */
    public string $schemeFilter = '';

    /** '' = semua status komisi */
    public string $statusFilter = '';

    /** '' = semua status setoran (hanya berlaku untuk baris Titip) */
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
     * Bagian D — bayar SATU baris komisi Bulanan (recurring/limited_count)
     * langsung dari halaman ini. Tanpa modal/bukti bayar (beda dari Titip).
     * Guard jendela payout ada di service.
     */
    public function payMonthlyRow(int $ledgerId, CommissionPayoutService $payoutService): void
    {
        $this->authorize('markPaid', CommissionLedger::class);

        $entry = CommissionLedger::query()->find($ledgerId);

        if ($entry === null) {
            return;
        }

        try {
            $payoutService->payMonthlyRow($entry, auth()->user());
            $this->flash = 'Komisi bulanan berhasil ditandai dibayar.';
        } catch (RuntimeException $e) {
            $this->addError('monthlyPay', $e->getMessage());
        }
    }

    /**
     * Bagian D — bayar SEMUA baris komisi Bulanan milik satu Referrer yang
     * jendela payout paketnya sedang terbuka (sisanya dilewati diam-diam).
     */
    public function payMonthlyReferrer(int $referrerId, CommissionPayoutService $payoutService): void
    {
        $this->authorize('markPaid', CommissionLedger::class);

        $affected = $payoutService->payMonthlyForReferrer($referrerId, auth()->user());

        $this->flash = $affected > 0
            ? "{$affected} komisi bulanan ditandai dibayar."
            : 'Tidak ada komisi bulanan yang bisa dibayar sekarang (harus Layak Dibayar dan dalam jendela payout paketnya).';
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

    /** Skema yang dianggap "Bulanan" di halaman ini. */
    private const MONTHLY_SCHEMES = [CommissionScheme::Recurring, CommissionScheme::LimitedCount];

    public function render(CommissionPayoutService $payoutService)
    {
        $monthlyValues = array_map(fn (CommissionScheme $s) => $s->value, self::MONTHLY_SCHEMES);
        $allValues = array_merge([CommissionScheme::Titip->value], $monthlyValues);

        $tenantAll = fn () => CommissionLedger::query()->whereIn('scheme', $allValues);

        // Kartu ringkasan — GLOBAL (tenant-scoped), independen dari filter,
        // dipisah per jenis + total gabungan.
        $totalTitipHarusDibayar = (float) $tenantAll()
            ->where('scheme', CommissionScheme::Titip->value)
            ->where('status', CommissionStatus::Eligible->value)
            ->sum('amount');

        $totalSetoranBelumMasuk = (float) $tenantAll()
            ->where('scheme', CommissionScheme::Titip->value)
            ->where('deposit_status', TitipDepositStatus::BelumSetor->value)
            ->sum('gross_amount');

        $totalBulananHarusDibayar = (float) $tenantAll()
            ->whereIn('scheme', $monthlyValues)
            ->where('status', CommissionStatus::Eligible->value)
            ->sum('amount');

        // Scope skema sesuai filter "Jenis Komisi".
        $schemeScope = match ($this->schemeFilter) {
            'titip' => [CommissionScheme::Titip->value],
            'bulanan' => $monthlyValues,
            default => $allValues,
        };

        $query = CommissionLedger::query()
            ->whereIn('scheme', $schemeScope)
            ->with(['customer:id,name,ppp_package_id', 'customer.pppPackage.commissionRate', 'referrer:id,name,phone', 'depositedBy:id,name', 'paidBy:id,name', 'invoice:id,invoice_number'])
            ->orderByDesc('id');

        if ($this->search !== '') {
            $search = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($search) {
                $q->whereHas('customer', fn ($c) => $c->where('name', 'like', $search))
                    ->orWhereHas('referrer', fn ($r) => $r->where('name', 'like', $search));
            });
        }

        if (CommissionStatus::tryFrom($this->statusFilter) !== null) {
            $query->where('status', $this->statusFilter);
        }

        // Filter status setoran — hanya bermakna untuk baris Titip.
        if (TitipDepositStatus::tryFrom($this->depositFilter) !== null) {
            $query->where('deposit_status', $this->depositFilter);
        }

        $selectedInt = array_map('intval', $this->selected);

        $isTitip = fn ($r) => $r->scheme === CommissionScheme::Titip;
        $isTitipPayable = fn ($r) => $isTitip($r)
            && $r->status === CommissionStatus::Eligible
            && $r->deposit_status === TitipDepositStatus::SudahSetor;
        $isMonthlyPayableNow = fn ($r) => in_array($r->scheme, self::MONTHLY_SCHEMES, true)
            && $r->status === CommissionStatus::Eligible
            && $payoutService->isRowPayableNow($r);

        $groups = $query->get()
            ->groupBy('referrer_id')
            ->map(function ($groupRows) use ($selectedInt, $isTitip, $isTitipPayable, $isMonthlyPayableNow) {
                $titipRows = $groupRows->filter($isTitip);
                $monthlyRows = $groupRows->reject($isTitip);

                $belumSetor = $titipRows->where('deposit_status', TitipDepositStatus::BelumSetor);
                $belumSetorIds = $belumSetor->pluck('id')->map(fn ($id) => (int) $id)->all();

                $titipPayable = $titipRows->filter($isTitipPayable);
                $monthlyPayable = $monthlyRows->filter($isMonthlyPayableNow);

                return [
                    'referrer' => $groupRows->first()->referrer,
                    'rows' => $groupRows,
                    'tx_count' => $groupRows->count(),
                    'total_commission' => (float) $groupRows->sum(fn ($r) => (float) ($r->amount ?? 0)),
                    'total_belum_setor' => (float) $belumSetor->sum(fn ($r) => (float) ($r->gross_amount ?? 0)),
                    'belum_setor_count' => $belumSetor->count(),
                    'all_belum_setor_selected' => $belumSetorIds !== []
                        && array_diff($belumSetorIds, $selectedInt) === [],
                    'payable_count' => $titipPayable->count(),
                    'payable_total' => (float) $titipPayable->sum(fn ($r) => (float) ($r->amount ?? 0)),
                    'monthly_payable_count' => $monthlyPayable->count(),
                    'monthly_payable_total' => (float) $monthlyPayable->sum(fn ($r) => (float) ($r->amount ?? 0)),
                ];
            })
            ->sortByDesc(fn ($g) => $g['total_belum_setor'] + $g['payable_total'] + $g['monthly_payable_total'])
            ->values();

        return view('livewire.commission.titip-masuk-index', [
            'groups' => $groups,
            'isTitip' => $isTitip,
            'isPayable' => $isTitipPayable,
            'isMonthlyPayableNow' => $isMonthlyPayableNow,
            'totalTitipHarusDibayar' => $totalTitipHarusDibayar,
            'totalSetoranBelumMasuk' => $totalSetoranBelumMasuk,
            'totalBulananHarusDibayar' => $totalBulananHarusDibayar,
            'totalGabungan' => $totalTitipHarusDibayar + $totalBulananHarusDibayar,
            'statuses' => CommissionStatus::cases(),
            'depositStatuses' => TitipDepositStatus::cases(),
            'canManage' => auth()->user()->can('markDeposit', CommissionLedger::class),
            'selectedCount' => count($selectedInt),
        ]);
    }
}
