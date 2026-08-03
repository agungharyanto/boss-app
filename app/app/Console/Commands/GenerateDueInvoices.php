<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\InvoiceService;
use Illuminate\Console\Command;

/**
 * Generates an invoice for every active subscription whose next due date
 * falls exactly --lead-days away (default 7 — the confirmed v0.3.4
 * decision, deliberately a flag rather than a silent hardcoded constant).
 * Run daily (see routes/console.php). Auto-issues (draft -> pending)
 * immediately since this is the fully-automated recurring path — there's
 * no manual review step in this sprint.
 */
class GenerateDueInvoices extends Command
{
    protected $signature = 'app:generate-due-invoices {--lead-days=7 : Hari sebelum due_date invoice digenerate}';

    protected $description = 'Generate invoice untuk subscription aktif yang due date-nya jatuh N hari lagi';

    public function handle(InvoiceService $invoiceService): int
    {
        $leadDays = (int) $this->option('lead-days');
        $targetDate = now()->addDays($leadDays)->toDateString();

        // withoutGlobalScopes: this runs with no authenticated request
        // (console/scheduler), so TenantScope has nothing to filter by —
        // this command is deliberately tenant-agnostic, iterating every
        // tenant's active subscriptions in one daily run.
        $subscriptions = Subscription::withoutGlobalScopes()
            ->where('status', SubscriptionStatus::Active->value)
            ->get();

        $generated = 0;

        foreach ($subscriptions as $subscription) {
            [$periodStart, $periodEnd, $dueDate] = $invoiceService->previewNextPeriod($subscription);

            if ($dueDate->toDateString() !== $targetDate) {
                continue;
            }

            $invoice = $invoiceService->generateForPeriod($subscription, $periodStart, $periodEnd, $dueDate);

            // wasRecentlyCreated is false when generateForPeriod's own
            // idempotency guard returned a pre-existing invoice instead —
            // skip auto-issuing an invoice that was already issued before.
            if (! $invoice->wasRecentlyCreated) {
                continue;
            }

            $invoiceService->markPending($invoice);
            $generated++;
            $this->info("Generated {$invoice->invoice_number} for subscription #{$subscription->id} (due {$dueDate->toDateString()}).");
        }

        $this->info("Done. {$generated} invoice(s) generated for due_date={$targetDate}.");

        return self::SUCCESS;
    }
}
