<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\ResellerPackagePricing;
use App\Models\Subscription;

class SubscriptionService
{
    /**
     * When `reseller_package_pricing_id` is given, that pricing row is
     * authoritative for `name`/`monthly_amount` — they're taken from it,
     * not from $data, so a subscription can never silently drift out of
     * sync with the reseller's own published pricing. For a direct-retail
     * subscription (no reseller_package_pricing — the customer isn't
     * reseller-attributed, or the admin explicitly wants a custom price),
     * `name`/`monthly_amount` in $data are used as-is.
     *
     * @param  array{reseller_package_pricing_id?: ?int, name?: string, monthly_amount?: float|string, billing_cycle_day: int, started_at?: string}  $data
     */
    public function create(Customer $customer, array $data): Subscription
    {
        $pricing = ! empty($data['reseller_package_pricing_id'])
            ? ResellerPackagePricing::findOrFail($data['reseller_package_pricing_id'])
            : null;

        return Subscription::create([
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'reseller_id' => $customer->reseller_id,
            'reseller_package_pricing_id' => $pricing?->id,
            'name' => $pricing?->name ?? $data['name'],
            'monthly_amount' => $pricing?->price ?? $data['monthly_amount'],
            'status' => SubscriptionStatus::Active,
            'billing_cycle_day' => $data['billing_cycle_day'],
            'started_at' => $data['started_at'] ?? now()->toDateString(),
        ]);
    }

    public function suspend(Subscription $subscription): Subscription
    {
        $subscription->update(['status' => SubscriptionStatus::Suspended]);

        return $subscription->refresh();
    }

    public function reactivate(Subscription $subscription): Subscription
    {
        $subscription->update(['status' => SubscriptionStatus::Active]);

        return $subscription->refresh();
    }

    public function cancel(Subscription $subscription): Subscription
    {
        $subscription->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);

        return $subscription->refresh();
    }
}
