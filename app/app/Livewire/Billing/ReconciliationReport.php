<?php

namespace App\Livewire\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookProcessingResult;
use App\Models\Invoice;
use App\Models\PaymentWebhookLog;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only audit report — no "auto-fix" action anywhere here, per confirmed
 * scope. Two sections: (1) paid invoices cross-referenced against their
 * matching payments row, flagging any paid invoice with no matching 'paid'
 * payment record as an anomaly (shouldn't happen given the atomic
 * transaction in PaymentService::handleWebhook, but worth surfacing if it
 * ever does — e.g. data touched directly outside the normal flow);
 * (2) every non-applied webhook log entry (rejected signature, rejected
 * amount mismatch, rejected invoice-not-found, duplicate), for manual
 * follow-up.
 */
class ReconciliationReport extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('viewAny', Invoice::class);
    }

    public function render()
    {
        $paidInvoices = Invoice::query()
            ->where('status', InvoiceStatus::Paid->value)
            ->with(['customer', 'payments'])
            ->latest('paid_at')
            ->paginate(15);

        $reconciliation = $paidInvoices->getCollection()->map(function (Invoice $invoice) {
            $matchedPayment = $invoice->payments->firstWhere('status', PaymentStatus::Paid);

            return [
                'invoice' => $invoice,
                'payment' => $matchedPayment,
                'is_anomaly' => $matchedPayment === null,
            ];
        });

        $anomalousWebhooks = PaymentWebhookLog::query()
            ->where('processing_result', '!=', WebhookProcessingResult::Applied->value)
            ->latest('created_at')
            ->limit(50)
            ->get();

        return view('livewire.billing.reconciliation-report', [
            'reconciliation' => $reconciliation,
            'paidInvoices' => $paidInvoices,
            'anomalousWebhooks' => $anomalousWebhooks,
        ]);
    }
}
