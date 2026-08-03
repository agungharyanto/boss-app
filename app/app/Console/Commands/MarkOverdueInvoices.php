<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Console\Command;

/**
 * Daily automatic transition (confirmed v0.3.4 decision): any invoice still
 * 'pending' once its due_date has passed becomes 'overdue'.
 */
class MarkOverdueInvoices extends Command
{
    protected $signature = 'app:mark-overdue-invoices';

    protected $description = 'Ubah status invoice pending yang due_date-nya sudah lewat menjadi overdue';

    public function handle(InvoiceService $invoiceService): int
    {
        // whereDate() — see TaxCalculationService::calculateForAmount for
        // why a plain where() string comparison against a 'date' cast
        // column isn't reliable across drivers.
        $invoices = Invoice::withoutGlobalScopes()
            ->where('status', InvoiceStatus::Pending->value)
            ->whereDate('due_date', '<', now()->toDateString())
            ->get();

        foreach ($invoices as $invoice) {
            $invoiceService->markOverdue($invoice);
            $this->info("Marked {$invoice->invoice_number} as overdue.");
        }

        $this->info("Done. {$invoices->count()} invoice(s) marked overdue.");

        return self::SUCCESS;
    }
}
