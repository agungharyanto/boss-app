<?php

namespace App\Livewire\Billing;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvalidInvoiceStatusTransitionException;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

class InvoiceIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $statusFilter = '';

    // v0.12.2 — filter rentang tanggal berdasarkan due_date (konsisten
    // dengan definisi 4 card ringkasan di bawah, semua berbasis due_date
    // kecuali "Terbayar Bulan Ini" yang berbasis paid_at).
    public string $dateFrom = '';

    public string $dateTo = '';

    public ?string $transitionError = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Invoice::class);
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatingDateTo(): void
    {
        $this->resetPage();
    }

    public function resetDateFilter(): void
    {
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->resetPage();
    }

    public function markPending(int $id, InvoiceService $service): void
    {
        $this->authorize('manage', Invoice::class);
        $this->attemptTransition(fn () => $service->markPending(Invoice::findOrFail($id)));
    }

    public function markPaid(int $id, InvoiceService $service): void
    {
        $this->authorize('manage', Invoice::class);
        $this->attemptTransition(fn () => $service->markPaid(Invoice::findOrFail($id)));
    }

    public function cancelInvoice(int $id, InvoiceService $service): void
    {
        $this->authorize('manage', Invoice::class);
        $this->attemptTransition(fn () => $service->cancel(Invoice::findOrFail($id)));
    }

    /**
     * The status buttons in the view are already gated by
     * status->canTransitionTo() so this shouldn't normally trigger — this
     * is a safety net against stale UI state (e.g. two admins acting on
     * the same invoice at once), surfaced as a flash message instead of an
     * uncaught exception (InvalidInvoiceStatusTransitionException's own
     * render() targets JSON API responses, not Livewire).
     */
    private function attemptTransition(callable $callback): void
    {
        $this->transitionError = null;

        try {
            $callback();
        } catch (InvalidInvoiceStatusTransitionException $e) {
            $this->transitionError = $e->getMessage();
        }
    }

    /**
     * v0.12.2 — 4 card ringkasan di atas tabel. Semua tenant/reseller-scoped
     * secara alami lewat `Invoice::query()` (sama seperti tabel utama di
     * bawah) — TIDAK pernah `withoutGlobalScopes()`. "Bulan ini"/"Terbayar
     * Bulan Ini" berbasis `now()`, bukan filter tanggal yang sedang aktif
     * di layar — kartu ini rangkuman tetap, independen dari filter tabel
     * (sama posture "Total Komisi Harus Dibayar" di Fee Komisi, v0.9.6).
     *
     * @return array{overdue_this_month: int, overdue_all: int, total_this_month: int, paid_this_month: int}
     */
    private function summaryCards(): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        return [
            'overdue_this_month' => Invoice::query()
                ->where('status', InvoiceStatus::Overdue->value)
                ->whereBetween('due_date', [$startOfMonth, $endOfMonth])
                ->count(),
            'overdue_all' => Invoice::query()
                ->where('status', InvoiceStatus::Overdue->value)
                ->count(),
            'total_this_month' => Invoice::query()
                ->whereBetween('due_date', [$startOfMonth, $endOfMonth])
                ->count(),
            'paid_this_month' => Invoice::query()
                ->where('status', InvoiceStatus::Paid->value)
                ->whereBetween('paid_at', [$startOfMonth, $endOfMonth])
                ->count(),
        ];
    }

    public function render()
    {
        $invoices = Invoice::query()
            ->with(['customer', 'reseller'])
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFrom !== '', fn ($q) => $q->whereDate('due_date', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->whereDate('due_date', '<=', $this->dateTo))
            ->latest('generated_at')
            ->paginate(15);

        return view('livewire.billing.invoice-index', [
            'invoices' => $invoices,
            'summary' => $this->summaryCards(),
            'canManage' => auth()->user()->can('manage', Invoice::class),
        ]);
    }
}
