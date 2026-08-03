<?php

namespace App\Livewire\Billing;

use App\Exceptions\InvalidInvoiceStatusTransitionException;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

class InvoiceIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $statusFilter = '';

    public ?string $transitionError = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Invoice::class);
    }

    public function updatingStatusFilter(): void
    {
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

    public function render()
    {
        $invoices = Invoice::query()
            ->with(['customer', 'reseller'])
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->latest('generated_at')
            ->paginate(15);

        return view('livewire.billing.invoice-index', [
            'invoices' => $invoices,
            'canManage' => auth()->user()->can('manage', Invoice::class),
        ]);
    }
}
