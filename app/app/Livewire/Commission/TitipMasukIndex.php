<?php

namespace App\Livewire\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\TitipDepositStatus;
use App\Models\CommissionLedger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

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
 * TETAP tanpa approve/reject.
 */
class TitipMasukIndex extends Component
{
    use AuthorizesRequests;

    public string $search = '';

    /** '' = semua status komisi */
    public string $statusFilter = '';

    /** '' = semua status setoran */
    public string $depositFilter = '';

    /** id baris commission_ledger yang dicentang untuk ditandai sudah setor */
    public array $selected = [];

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', CommissionLedger::class);
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
            ->with(['customer:id,name', 'referrer:id,name,phone', 'depositedBy:id,name'])
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

        $groups = $query->get()
            ->groupBy('referrer_id')
            ->map(function ($groupRows) use ($selectedInt) {
                $belumSetor = $groupRows->where('deposit_status', TitipDepositStatus::BelumSetor);
                $belumSetorIds = $belumSetor->pluck('id')->map(fn ($id) => (int) $id)->all();

                return [
                    'referrer' => $groupRows->first()->referrer,
                    'rows' => $groupRows,
                    'tx_count' => $groupRows->count(),
                    'total_commission' => (float) $groupRows->sum(fn ($r) => (float) ($r->amount ?? 0)),
                    'total_belum_setor' => (float) $belumSetor->sum(fn ($r) => (float) ($r->gross_amount ?? 0)),
                    'belum_setor_count' => $belumSetor->count(),
                    'all_belum_setor_selected' => $belumSetorIds !== []
                        && array_diff($belumSetorIds, $selectedInt) === [],
                ];
            })
            ->sortByDesc('total_belum_setor')
            ->values();

        return view('livewire.commission.titip-masuk-index', [
            'groups' => $groups,
            'totalKomisiHarusDibayar' => $totalKomisiHarusDibayar,
            'totalSetoranBelumMasuk' => $totalSetoranBelumMasuk,
            'statuses' => CommissionStatus::cases(),
            'depositStatuses' => TitipDepositStatus::cases(),
            'canManage' => auth()->user()->can('markDeposit', CommissionLedger::class),
            'selectedCount' => count($selectedInt),
        ]);
    }
}
