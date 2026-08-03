<?php

namespace App\Services\Tax;

use App\Enums\TaxBurden;
use App\Models\Reseller;
use App\Models\ResellerTaxPolicy;
use App\Models\TaxComponent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * All queries here rely on ResellerTaxPolicy's tenant-scoping (TenantScope,
 * via Auth::user()->tenant_id — same convention as every other tenant-scoped
 * model in this codebase). Console/queued callers (e.g. seeders) must
 * Auth::login() a user of the target tenant before calling into this
 * service — there is no explicit $tenant parameter by design, matching how
 * every other Service in this codebase resolves "current tenant".
 */
class ResellerTaxPolicyService
{
    /**
     * @throws InvalidArgumentException if burden=split without a valid 0-100 splitRatio
     */
    public function setPolicy(?Reseller $reseller, TaxComponent $component, string $burden, ?float $splitRatio, Carbon $effectiveFrom): ResellerTaxPolicy
    {
        $burdenEnum = TaxBurden::from($burden);

        if ($burdenEnum === TaxBurden::Split) {
            if ($splitRatio === null || $splitRatio < 0 || $splitRatio > 100) {
                throw new InvalidArgumentException('split_ratio wajib diisi (0-100) ketika burden=split.');
            }
        } else {
            $splitRatio = null;
        }

        return DB::transaction(function () use ($reseller, $component, $burdenEnum, $splitRatio, $effectiveFrom) {
            // Effective-date: close out whatever open-ended (effective_to
            // null) policy currently covers this reseller+component combo —
            // same pattern as TaxComponentService::updateRate — so
            // getActivePolicies() never finds two open-ended rows for the
            // same pair.
            $this->policiesQuery($reseller, $component)
                ->whereNull('effective_to')
                ->update(['effective_to' => $effectiveFrom->copy()->subDay()]);

            return ResellerTaxPolicy::create([
                'tenant_id' => $component->tenant_id,
                'reseller_id' => $reseller?->id,
                'tax_component_id' => $component->id,
                'burden' => $burdenEnum,
                'split_ratio' => $splitRatio,
                'is_active' => true,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'set_by' => Auth::id(),
            ]);
        });
    }

    /**
     * All active policies applicable to $reseller on $date, keyed by their
     * linked tax_component's `code` — NOT by tax_component_id. Policies are
     * matched across effective-dated tax_component rate changes by `code`
     * (the documented stable identifier) rather than the specific row id,
     * because TaxComponentService::updateRate() inserts a new
     * tax_components row (new id, same code) on every rate change, and a
     * burden/split agreement set against the old id must keep applying —
     * it shouldn't need re-entry just because a tax rate changed. Keying
     * (and therefore de-duplicating reseller-specific vs. direct-retail)
     * by code here, rather than leaving it to the caller, is what makes
     * that guarantee hold even when the reseller-specific and
     * direct-retail policies for the "same" tax happen to reference
     * different tax_component_id generations.
     *
     * A reseller-specific policy always wins over the direct-retail policy
     * for the same code; direct-retail fills in for any code the reseller
     * has no override for. Passing $reseller = null returns the
     * direct-retail policies as-is.
     */
    public function getActivePolicies(?Reseller $reseller, Carbon $date): Collection
    {
        // ->toBase() is load-bearing: Illuminate\Database\Eloquent\Collection
        // overrides merge() to dictionary-merge by each MODEL's primary key,
        // completely ignoring any custom ->keyBy() keys — silently
        // reindexing the result and defeating the code-based de-dup this
        // method exists to do. The plain Illuminate\Support\Collection
        // ->toBase() returns merges by the collection's actual (string) keys
        // instead, which is what we need here.
        $direct = $this->activePoliciesQueryForDate(null, $date)->with('taxComponent')->get()
            ->keyBy(fn (ResellerTaxPolicy $policy) => $policy->taxComponent->code)
            ->toBase();

        if ($reseller === null) {
            return $direct;
        }

        $resellerSpecific = $this->activePoliciesQueryForDate($reseller, $date)->with('taxComponent')->get()
            ->keyBy(fn (ResellerTaxPolicy $policy) => $policy->taxComponent->code)
            ->toBase();

        return $direct->merge($resellerSpecific);
    }

    private function activePoliciesQueryForDate(?Reseller $reseller, Carbon $date): Builder
    {
        return $this->policiesQuery($reseller, null)
            ->where('is_active', true)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date->toDateString()));
    }

    private function policiesQuery(?Reseller $reseller, ?TaxComponent $component): Builder
    {
        return ResellerTaxPolicy::query()
            ->when(
                $reseller === null,
                fn (Builder $q) => $q->whereNull('reseller_id'),
                fn (Builder $q) => $q->where('reseller_id', $reseller->id),
            )
            ->when($component !== null, fn (Builder $q) => $q->where('tax_component_id', $component->id));
    }
}
