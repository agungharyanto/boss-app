<?php

namespace App\Services\Tax;

use App\Enums\RemittanceStatus;
use App\Enums\TaxLedgerStatus;
use App\Models\KomdigiRemittanceSummary;
use App\Models\ResellerTaxLedger;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class RemittanceSummaryService
{
    /**
     * Aggregates reseller_tax_ledger rows (status != voided) whose
     * transaction_date falls within [$periodStart, $periodEnd] into
     * komdigi_remittance_summary, grouped by reseller_id (including null =
     * direct-retail aggregate) + tax_component_id. Safe to re-run for the
     * same period (upserts via updateOrCreate) as long as the target row
     * isn't already finalized/remitted — re-generating over an already
     * reported figure would silently rewrite it, so that throws instead.
     */
    public function generateForPeriod(Carbon $periodStart, Carbon $periodEnd): void
    {
        // whereDate() throughout this method (not plain where() string
        // comparisons) — see TaxCalculationService::calculateForAmount for
        // why a 'date' cast column can't be safely compared against a
        // toDateString() value with a raw where()/array-match across
        // drivers.
        $groups = ResellerTaxLedger::query()
            ->whereDate('transaction_date', '>=', $periodStart->toDateString())
            ->whereDate('transaction_date', '<=', $periodEnd->toDateString())
            ->where('status', '!=', TaxLedgerStatus::Voided->value)
            ->get()
            ->groupBy(fn (ResellerTaxLedger $entry) => $entry->reseller_id.'|'.$entry->tax_component_id);

        foreach ($groups as $group) {
            /** @var Collection<int, ResellerTaxLedger> $group */
            $first = $group->first();

            $existing = KomdigiRemittanceSummary::query()
                ->where('tenant_id', $first->tenant_id)
                ->whereDate('period_start', $periodStart->toDateString())
                ->whereDate('period_end', $periodEnd->toDateString())
                ->where('tax_component_id', $first->tax_component_id)
                ->when(
                    $first->reseller_id === null,
                    fn ($q) => $q->whereNull('reseller_id'),
                    fn ($q) => $q->where('reseller_id', $first->reseller_id),
                )
                ->first();

            if ($existing !== null && $existing->status !== RemittanceStatus::Draft) {
                throw new RuntimeException(
                    "Remittance summary for {$periodStart->toDateString()}..{$periodEnd->toDateString()} ".
                    "(reseller_id={$first->reseller_id}, tax_component_id={$first->tax_component_id}) ".
                    "is already {$existing->status->value} — cannot regenerate."
                );
            }

            $attributes = [
                'total_base_amount' => round($group->sum('base_amount'), 2),
                'total_tax_amount' => round($group->sum('tax_amount'), 2),
                'total_customer_borne' => round($group->sum('customer_borne_amount'), 2),
                'total_reseller_borne' => round($group->sum('reseller_borne_amount'), 2),
                'transaction_count' => $group->count(),
                'status' => RemittanceStatus::Draft,
                'generated_at' => now(),
            ];

            if ($existing !== null) {
                $existing->update($attributes);
            } else {
                KomdigiRemittanceSummary::create([
                    'tenant_id' => $first->tenant_id,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'reseller_id' => $first->reseller_id,
                    'tax_component_id' => $first->tax_component_id,
                    ...$attributes,
                ]);
            }
        }
    }

    public function finalize(KomdigiRemittanceSummary $summary): void
    {
        $summary->update(['status' => RemittanceStatus::Finalized, 'generated_at' => now()]);
    }
}
