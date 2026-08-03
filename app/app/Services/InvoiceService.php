<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvalidInvoiceStatusTransitionException;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Tax\TaxCalculationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(
        private readonly TaxCalculationService $taxService,
        private readonly InvoiceNumberService $numberService,
    ) {}

    /**
     * Computes $subscription's next unbilled period (from its last invoice's
     * period_end, or started_at if it has none yet) and generates an
     * invoice for it. Idempotent: if an invoice already exists for that
     * exact period (the DB unique constraint on subscription_id+period_start
     * +period_end is the hard safety net), the EXISTING invoice is returned
     * instead of creating a duplicate — safe to call repeatedly from a
     * scheduled job without double-billing a customer.
     *
     * Known limitation (deferred to a later sprint, not silently dropped):
     * no proration — a subscription that starts/stops mid-period is still
     * billed for the full period.
     */
    public function generateNextForSubscription(Subscription $subscription): Invoice
    {
        [$periodStart, $periodEnd, $dueDate] = $this->resolvePeriod($subscription);

        return $this->generateForPeriod($subscription, $periodStart, $periodEnd, $dueDate);
    }

    /**
     * STABLE CONTRACT (RULE from v0.3.3, see CLAUDE.md "Tax engine
     * integration contract"): every invoice generation calls
     * TaxCalculationService::calculateForAmount() then ::writeLedgerEntry()
     * — never re-derive tax manually, never re-query reseller_tax_policies
     * directly, never recompute grand_total by hand
     * ($breakdown->grandTotal is authoritative).
     *
     * writeLedgerEntry() is called exactly ONCE per invoice here — it
     * internally writes one reseller_tax_ledger row per TaxBreakdown
     * component (see its own PHPDoc), which is the correct snapshot
     * behavior, not a bug: rate_applied/burden_applied per component must
     * stay reconstructable even if tax_components/reseller_tax_policies
     * change later.
     *
     * Idempotent regardless of caller: if an invoice already exists for
     * this exact subscription+period, the EXISTING invoice is returned
     * instead of creating a duplicate (checked inside the same transaction
     * as the insert, so this is also safe under concurrent calls — the
     * DB's unique constraint on subscription_id+period_start+period_end is
     * the last-resort backstop, not the primary guard).
     */
    public function generateForPeriod(Subscription $subscription, Carbon $periodStart, Carbon $periodEnd, Carbon $dueDate): Invoice
    {
        return DB::transaction(function () use ($subscription, $periodStart, $periodEnd, $dueDate) {
            // whereDate() — a plain where() string-compares the raw stored
            // value, which can carry a time suffix depending on driver (see
            // TaxCalculationService::calculateForAmount for the full
            // explanation); whereDate() compares the date part only,
            // reliably across drivers.
            $existing = Invoice::where('subscription_id', $subscription->id)
                ->whereDate('period_start', $periodStart->toDateString())
                ->whereDate('period_end', $periodEnd->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $reseller = $subscription->reseller;
            $baseAmount = (float) $subscription->monthly_amount;

            $breakdown = $this->taxService->calculateForAmount($reseller, $baseAmount, $periodStart);
            $invoiceNumber = $this->numberService->next($subscription->tenant_id, $reseller, $periodStart);

            $invoice = Invoice::create([
                'tenant_id' => $subscription->tenant_id,
                'customer_id' => $subscription->customer_id,
                'subscription_id' => $subscription->id,
                'reseller_id' => $reseller?->id,
                'invoice_number' => $invoiceNumber,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'due_date' => $dueDate,
                'status' => InvoiceStatus::Draft,
                'subtotal' => $breakdown->baseAmount,
                'tax_total' => $breakdown->totalTax,
                'grand_total' => $breakdown->grandTotal,
                'generated_at' => now(),
            ]);

            $invoice->lineItems()->create([
                'tenant_id' => $subscription->tenant_id,
                'description' => $subscription->name,
                'quantity' => 1,
                'unit_price' => $baseAmount,
                'line_total' => $baseAmount,
            ]);

            $this->taxService->writeLedgerEntry($breakdown, $reseller, Invoice::class, $invoice->id, $periodStart, 'system');

            // ->load() (not ->fresh()) deliberately — fresh() returns a
            // brand-new model instance queried from the DB, which resets
            // wasRecentlyCreated to false. Callers (GenerateDueInvoices)
            // rely on that flag to tell "just created" apart from "already
            // existed" without an extra query.
            return $invoice->load('lineItems');
        });
    }

    /**
     * Auto-issues a freshly generated invoice — used by the scheduled
     * generation command, which has no manual review step. Admin-created
     * invoices (if ever added) could stay 'draft' longer before this is
     * called explicitly.
     */
    public function markPending(Invoice $invoice): Invoice
    {
        return $this->transition($invoice, InvoiceStatus::Pending);
    }

    public function markPaid(Invoice $invoice): Invoice
    {
        $invoice = $this->transition($invoice, InvoiceStatus::Paid);
        $invoice->update(['paid_at' => now()]);

        return $invoice->fresh();
    }

    public function markOverdue(Invoice $invoice): Invoice
    {
        return $this->transition($invoice, InvoiceStatus::Overdue);
    }

    public function cancel(Invoice $invoice): Invoice
    {
        return $this->transition($invoice, InvoiceStatus::Cancelled);
    }

    private function transition(Invoice $invoice, InvoiceStatus $target): Invoice
    {
        if (! $invoice->status->canTransitionTo($target)) {
            throw new InvalidInvoiceStatusTransitionException($invoice->status, $target);
        }

        $invoice->update(['status' => $target]);

        return $invoice->fresh();
    }

    /**
     * Read-only preview of what generateNextForSubscription() would
     * generate for — used by the scheduled command to check "is this
     * subscription's next due date exactly N days away" without creating
     * anything.
     *
     * @return array{0: Carbon, 1: Carbon, 2: Carbon} [periodStart, periodEnd, dueDate]
     */
    public function previewNextPeriod(Subscription $subscription): array
    {
        return $this->resolvePeriod($subscription);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon} [periodStart, periodEnd, dueDate]
     */
    private function resolvePeriod(Subscription $subscription): array
    {
        $lastInvoice = $subscription->invoices()->orderByDesc('period_end')->first();

        $periodStart = $lastInvoice !== null
            ? $lastInvoice->period_end->copy()->addDay()
            : $subscription->started_at->copy();

        $dueDate = $this->cycleDateOnOrAfter($periodStart, $subscription->billing_cycle_day);
        $periodEnd = $dueDate->copy();

        return [$periodStart, $periodEnd, $dueDate];
    }

    /**
     * Next date on/after $date whose day-of-month matches $billingCycleDay
     * (clamped to the target month's actual length — e.g. cycle day 31 in
     * February lands on the 28th/29th).
     */
    private function cycleDateOnOrAfter(Carbon $date, int $billingCycleDay): Carbon
    {
        $reference = $date->copy()->startOfDay();
        $day = min($billingCycleDay, $reference->daysInMonth);
        $candidate = $reference->copy()->day($day);

        if ($candidate->lt($reference)) {
            $candidate = $candidate->addMonthNoOverflow();
            $day = min($billingCycleDay, $candidate->daysInMonth);
            $candidate = $candidate->day($day);
        }

        return $candidate;
    }
}
