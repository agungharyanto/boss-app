<?php

namespace App\Services;

use App\Enums\ResellerPackagePricingStatus;
use App\Models\Reseller;
use App\Models\ResellerPackagePricing;

class ResellerPackagePricingService
{
    /**
     * status is set explicitly for the same reason as
     * App\Services\ResellerService::createReseller — Eloquent doesn't
     * hydrate the DB-level default back onto the in-memory model after
     * insert, and the API resource reads ->status->value right after.
     *
     * @param  array{name: string, description?: ?string, price: float|string, is_custom?: bool}  $data
     */
    public function createPackage(Reseller $reseller, array $data): ResellerPackagePricing
    {
        return $reseller->packagePricing()->create([...$data, 'status' => ResellerPackagePricingStatus::Active]);
    }

    /**
     * @param  array{name?: string, description?: ?string, price?: float|string, is_custom?: bool}  $data
     */
    public function updatePackage(ResellerPackagePricing $pricing, array $data): ResellerPackagePricing
    {
        $pricing->update($data);

        return $pricing->refresh();
    }

    public function deactivatePackage(ResellerPackagePricing $pricing): ResellerPackagePricing
    {
        $pricing->update(['status' => ResellerPackagePricingStatus::Inactive]);

        return $pricing->refresh();
    }
}
