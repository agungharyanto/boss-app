<?php

namespace App\Services\Tax;

use App\Models\TaxComponent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TaxComponentService
{
    /**
     * @param  array{tenant_id?: int, code: string, name: string, type: string, rate: float|string, is_active?: bool, effective_from: string, effective_to?: ?string, description?: ?string}  $data
     */
    public function create(array $data): TaxComponent
    {
        $data['created_by'] ??= Auth::id();
        $data['is_active'] ??= true;

        return TaxComponent::create($data);
    }

    /**
     * Effective-dates a rate change: closes $current's effective_to the day
     * before $effectiveFrom, then inserts a NEW row (same tenant/code/name/
     * type/description) with $newRate starting $effectiveFrom. $current's
     * rate is never mutated in place — App\Models\ResellerTaxLedger.rate_applied
     * snapshots the rate at calculation time, and that snapshot must stay
     * reconstructable from tax_components' history (audit trail) rather
     * than silently changing meaning if history were overwritten.
     */
    public function updateRate(TaxComponent $current, float $newRate, Carbon $effectiveFrom): TaxComponent
    {
        return DB::transaction(function () use ($current, $newRate, $effectiveFrom) {
            $current->update(['effective_to' => $effectiveFrom->copy()->subDay()]);

            return TaxComponent::create([
                'tenant_id' => $current->tenant_id,
                'code' => $current->code,
                'name' => $current->name,
                'type' => $current->type,
                'rate' => $newRate,
                'is_active' => $current->is_active,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'description' => $current->description,
                'created_by' => Auth::id(),
            ]);
        });
    }

    public function toggleActive(TaxComponent $component, bool $active): void
    {
        $component->update(['is_active' => $active]);
    }
}
