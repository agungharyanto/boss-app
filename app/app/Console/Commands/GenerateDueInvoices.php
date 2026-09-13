<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\PppPackage;
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
 *
 * v0.12.2 — `subscriptions` has no `ppp_package_id` of its own (that FK
 * lives on `customers`, see v0.9.4) — the package has to be resolved via
 * `$subscription->customer->ppp_package_id` here, unlike
 * `RenewalInvoiceService`/`PppoeVlan10MigrationService`, which already had
 * a `Customer` in hand. `PppPackage::hasZeroSellPrice()` (the same shared
 * check those two use) then skips this ONE RUN's generation for that
 * subscription — never permanent: if the customer's package changes later
 * to a paid one, this same code path simply stops skipping on the next
 * run, no separate un-skip logic needed.
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
            ->with(['customer' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('status', SubscriptionStatus::Active->value)
            ->get();

        $generated = 0;
        $skippedZeroPrice = 0;

        foreach ($subscriptions as $subscription) {
            [$periodStart, $periodEnd, $dueDate] = $invoiceService->previewNextPeriod($subscription);

            if ($dueDate->toDateString() !== $targetDate) {
                continue;
            }

            $pppPackageId = $subscription->customer?->ppp_package_id;
            $package = $pppPackageId !== null ? PppPackage::withoutGlobalScopes()->find($pppPackageId) : null;

            if (PppPackage::hasZeroSellPrice($package)) {
                $skippedZeroPrice++;
                $this->info("Skipped subscription #{$subscription->id} (due {$dueDate->toDateString()}) — paket '{$package->name}' sell_price=0.");

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

        $this->info("Done. {$generated} invoice(s) generated, {$skippedZeroPrice} skipped (sell_price=0) for due_date={$targetDate}.");

        return self::SUCCESS;
    }
}
