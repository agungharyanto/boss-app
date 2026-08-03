<?php

namespace App\Services\Tax;

use App\DataTransferObjects\TaxBreakdown;
use App\Enums\TaxBurden;
use App\Enums\TaxComponentType;
use App\Models\Reseller;
use App\Models\ResellerTaxLedger;
use App\Models\TaxComponent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TaxCalculationService
{
    public function __construct(private readonly ResellerTaxPolicyService $policyService) {}

    /**
     * Resolves every currently-effective tax_component for $date (default
     * now) and computes the tax owed on $baseAmount, splitting each
     * component's tax_amount between customer/reseller per its resolved
     * policy — reseller-specific policy wins, direct-retail fills any gap
     * (see ResellerTaxPolicyService::getActivePolicies). A tax_component
     * with NO resolved policy at all (neither reseller-specific nor
     * direct-retail configured yet) is silently skipped — there's no burden
     * to apply, so no tax is computed for it until an admin sets a policy.
     *
     * Relies on the caller's TenantScope (Auth::user()->tenant_id) to
     * resolve which tenant's tax_components/policies apply, same convention
     * as every other tenant-scoped query in this codebase. Console/queued
     * callers must Auth::login() a user of the target tenant first.
     */
    public function calculateForAmount(?Reseller $reseller, float $baseAmount, ?Carbon $date = null): TaxBreakdown
    {
        $date ??= now();

        // whereDate() (not a plain where() string comparison) — a 'date'
        // cast column can be stored with a time suffix depending on driver
        // (e.g. SQLite keeps "2026-08-01 00:00:00" as-is; Postgres's native
        // DATE type doesn't), so a raw string comparison against
        // toDateString() silently mismatches on the exact boundary date.
        // whereDate() extracts the date part on both sides regardless.
        $components = TaxComponent::query()
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()))
            ->get();

        // Already keyed by tax_component `code`, not id — see
        // ResellerTaxPolicyService::getActivePolicies for why.
        $policies = $this->policyService->getActivePolicies($reseller, $date);

        $rows = [];

        foreach ($components as $component) {
            $policy = $policies->get($component->code);

            if ($policy === null) {
                continue;
            }

            $taxAmount = $component->type === TaxComponentType::Fixed
                ? round((float) $component->rate, 2)
                : round($baseAmount * (float) $component->rate / 100, 2);

            // split_ratio is the CUSTOMER's share (matches field order in
            // both the migration and TaxBreakdown: customer before
            // reseller). Customer's share rounds first, reseller's share
            // absorbs the remainder — guarantees customer+reseller always
            // sums back to tax_amount exactly, with no stray rounding cent
            // lost either way.
            [$customerAmount, $resellerAmount] = match ($policy->burden) {
                TaxBurden::CustomerBorne => [$taxAmount, 0.0],
                TaxBurden::ResellerBorne => [0.0, $taxAmount],
                TaxBurden::Split => (function () use ($taxAmount, $policy) {
                    $customerShare = round($taxAmount * (float) $policy->split_ratio / 100, 2);

                    return [$customerShare, round($taxAmount - $customerShare, 2)];
                })(),
            };

            $rows[] = [
                'tax_component_id' => $component->id,
                'code' => $component->code,
                'name' => $component->name,
                'rate' => (float) $component->rate,
                'tax_amount' => $taxAmount,
                'burden' => $policy->burden->value,
                'customer_amount' => $customerAmount,
                'reseller_amount' => $resellerAmount,
            ];
        }

        $totalTax = round(array_sum(array_column($rows, 'tax_amount')), 2);
        $totalCustomerBorne = round(array_sum(array_column($rows, 'customer_amount')), 2);
        $totalResellerBorne = round(array_sum(array_column($rows, 'reseller_amount')), 2);

        return new TaxBreakdown(
            baseAmount: $baseAmount,
            components: $rows,
            totalTax: $totalTax,
            totalCustomerBorne: $totalCustomerBorne,
            totalResellerBorne: $totalResellerBorne,
            grandTotal: round($baseAmount + $totalTax, 2),
        );
    }

    /**
     * STABLE CONTRACT for App\Services\InvoiceService (v0.3.4) — see
     * CLAUDE.md's tax engine integration contract for the exact call
     * sequence an invoice-generation flow must follow. Writes one
     * reseller_tax_ledger row per TaxBreakdown component.
     *
     * $referenceType/$referenceId are the polymorphic link back to whatever
     * triggered this (e.g. App\Models\Invoice::class, $invoice->id starting
     * v0.3.4) — both stay nullable/generic here on purpose (see the
     * reseller_tax_ledger migration: no FK constraint on either column), so
     * no migration is needed when v0.3.4 starts populating them.
     *
     * tenant_id is derived from $reseller (when given) or from the first
     * component's tax_component, NOT from Auth::user() — unlike the rest of
     * this Service, this specific method must stay correct even if a future
     * caller (e.g. a queued invoice-generation job) runs without an
     * authenticated request context.
     *
     * @return array<int, ResellerTaxLedger> the created ledger rows, one per TaxBreakdown component (empty array if the breakdown had none)
     */
    public function writeLedgerEntry(
        TaxBreakdown $breakdown,
        ?Reseller $reseller,
        ?string $referenceType,
        ?int $referenceId,
        Carbon $transactionDate,
        string $source = 'system',
    ): array {
        if ($breakdown->components === []) {
            return [];
        }

        $tenantId = $reseller?->tenant_id
            ?? TaxComponent::withoutGlobalScopes()->findOrFail($breakdown->components[0]['tax_component_id'])->tenant_id;

        return DB::transaction(function () use ($breakdown, $reseller, $referenceType, $referenceId, $transactionDate, $source, $tenantId) {
            return collect($breakdown->components)
                ->map(fn (array $component) => ResellerTaxLedger::create([
                    'tenant_id' => $tenantId,
                    'reseller_id' => $reseller?->id,
                    'tax_component_id' => $component['tax_component_id'],
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'base_amount' => $breakdown->baseAmount,
                    'rate_applied' => $component['rate'],
                    'tax_amount' => $component['tax_amount'],
                    'burden_applied' => $component['burden'],
                    'customer_borne_amount' => $component['customer_amount'],
                    'reseller_borne_amount' => $component['reseller_amount'],
                    'transaction_date' => $transactionDate,
                    'status' => 'pending',
                    'source' => $source,
                ]))
                ->all();
        });
    }
}
