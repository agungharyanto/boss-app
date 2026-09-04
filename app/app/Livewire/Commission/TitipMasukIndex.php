<?php

namespace App\Livewire\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\TitipDepositStatus;
use App\Models\CommissionLedger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * v0.9.6 — daftar kerja operasional "Titip Masuk": semua baris
 * `commission_ledger` scheme=titip (dibuat lewat aksi "Perpanjang" di
 * Daftar Pelanggan, langsung status Eligible setelah OTP terverifikasi).
 *
 * Sprint "perpanjang-daftar-pelanggan" (tracking setoran) — sekarang JUGA
 * melacak `gross_amount` (uang cash penuh yang dipegang Referrer) dan
 * `deposit_status` (`belum_setor`/`sudah_setor`). Tampilan dikelompokkan
 * per Referrer + kartu ringkasan (total komisi harus dibayar, total
 * setoran belum masuk) + aksi "Tandai Sudah Setor Semua" per Referrer
 * (`commission_ledger.manage`).
 *
 * TETAP tanpa approve/reject — OTP WhatsApp ke Referrer + nominal terkunci
 * ke rate sudah jadi jaring pengaman. Perpanjangan layanan tetap manual di
 * MixRadius.
 */
class TitipMasukIndex extends Component
{
    use AuthorizesRequests;

    public string $search = '';

    /** '' = semua status komisi */
    public string $statusFilter = '';

    /** '' = semua status setoran */
    public string $depositFilter = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', CommissionLedger::class);
    }

    public function markDepositedForReferrer(int $referrerId): void
    {
        $this->authorize('markDeposit', CommissionLedger::class);

        $affected = CommissionLedger::query()
            ->where('scheme', CommissionScheme::Titip->value)
            ->where('referrer_id', $referrerId)
            ->where('deposit_status', TitipDepositStatus::BelumSetor->value)
            ->update([
                'deposit_status' => TitipDepositStatus::SudahSetor->value,
                'deposited_at' => now(),
                'deposited_by' => auth()->id(),
            ]);

        $this->flash = $affected > 0
            ? "{$affected} transaksi titip ditandai sudah setor."
            : 'Tidak ada transaksi yang perlu ditandai.';
    }

    public function render()
    {
        $base = CommissionLedger::query()->where('scheme', CommissionScheme::Titip->value);

        // Kartu ringkasan — GLOBAL (tenant-scoped), independen dari filter di
        // bawah, supaya angka "berapa harus ditagih / dibayar" stabil.
        $totalKomisiHarusDibayar = (float) (clone $base)
            ->where('status', CommissionStatus::Eligible->value)
            ->sum('amount');

        $totalSetoranBelumMasuk = (float) (clone $base)
            ->where('deposit_status', TitipDepositStatus::BelumSetor->value)
            ->sum('gross_amount');

        $rows = (clone $base)
            ->with(['customer:id,name', 'referrer:id,name,phone', 'depositedBy:id,name'])
            ->when($this->search !== '', function ($query) {
                $search = '%'.trim($this->search).'%';
                $query->where(function ($q) use ($search) {
                    $q->whereHas('customer', fn ($c) => $c->where('name', 'like', $search))
                        ->orWhereHas('referrer', fn ($r) => $r->where('name', 'like', $search));
                });
            })
            ->when(
                $this->statusFilter !== '' && CommissionStatus::tryFrom($this->statusFilter),
                fn ($query) => $query->where('status', $this->statusFilter),
            )
            ->when(
                $this->depositFilter !== '' && TitipDepositStatus::tryFrom($this->depositFilter),
                fn ($query) => $query->where('deposit_status', $this->depositFilter),
            )
            ->orderByDesc('id')
            ->get();

        $groups = $rows
            ->groupBy('referrer_id')
            ->map(function ($groupRows) {
                $belumSetor = $groupRows->where('deposit_status', TitipDepositStatus::BelumSetor);

                return [
                    'referrer' => $groupRows->first()->referrer,
                    'rows' => $groupRows,
                    'tx_count' => $groupRows->count(),
                    'total_commission' => (float) $groupRows->sum(fn ($r) => (float) ($r->amount ?? 0)),
                    'total_belum_setor' => (float) $belumSetor->sum(fn ($r) => (float) ($r->gross_amount ?? 0)),
                    'belum_setor_count' => $belumSetor->count(),
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
        ]);
    }
}
