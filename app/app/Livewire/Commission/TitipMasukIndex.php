<?php

namespace App\Livewire\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Models\CommissionLedger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * v0.9.6 — daftar kerja operasional "Titip Masuk". Menampilkan SEMUA baris
 * `commission_ledger` scheme=titip (dibuat lewat Portal Referrer, langsung
 * status Eligible setelah OTP terverifikasi).
 *
 * MURNI informational — TIDAK ada tombol approve/reject di sini. Tujuannya:
 * admin tahu pelanggan mana yang cash-nya sudah masuk lewat Referrer,
 * supaya bisa memperpanjang layanan secara MANUAL di MixRadius (tidak ada
 * otomasi NAS/RADIUS di sprint ini). Gate approval bukan konsep v0.9.6 —
 * OTP WhatsApp ke Referrer sudah jadi jaring pengaman, nominal dikunci ke
 * CommissionRate.
 */
class TitipMasukIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    /** '' = semua status */
    public string $statusFilter = '';

    public function mount(): void
    {
        $this->authorize('viewAny', CommissionLedger::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $entries = CommissionLedger::query()
            ->where('scheme', CommissionScheme::Titip->value)
            ->with(['customer:id,name', 'referrer:id,name,phone'])
            ->when($this->search !== '', function ($query) {
                $search = "%{$this->search}%";
                $query->where(function ($q) use ($search) {
                    $q->whereHas('customer', fn ($c) => $c->where('name', 'like', $search))
                        ->orWhereHas('referrer', fn ($r) => $r->where('name', 'like', $search));
                });
            })
            ->when(
                $this->statusFilter !== '' && CommissionStatus::tryFrom($this->statusFilter),
                fn ($query) => $query->where('status', $this->statusFilter),
            )
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.commission.titip-masuk-index', [
            'entries' => $entries,
            'statuses' => CommissionStatus::cases(),
        ]);
    }
}
