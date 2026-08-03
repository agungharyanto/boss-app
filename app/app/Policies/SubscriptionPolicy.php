<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\ResellerUser;
use App\Models\Subscription;
use App\Models\User;

/**
 * Admin-only for create/update (subscriptions.manage) — matches the
 * "billing & finance is admin-controlled" posture from v0.3.2/v0.3.3.
 * Reseller owner/staff get read-only visibility into their own reseller's
 * subscriptions (view/viewAny), same isolation shape as
 * ResellerPackagePricingPolicy — not a Spatie permission, since they're
 * external users, not internal ISP staff.
 */
class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('subscriptions.view') || $this->belongsToAnyReseller($user);
    }

    public function view(User $user, Subscription $subscription): bool
    {
        if ($user->can('subscriptions.view')) {
            return true;
        }

        if ($subscription->reseller_id === null) {
            return false;
        }

        return $this->belongsToReseller($user, $subscription->reseller_id);
    }

    public function create(User $user): bool
    {
        return $user->can('subscriptions.manage');
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return $user->can('subscriptions.manage');
    }

    private function belongsToReseller(User $user, int $resellerId): bool
    {
        return ResellerUser::query()
            ->where('reseller_id', $resellerId)
            ->where('user_id', $user->id)
            ->where('status', ResellerUserStatus::Active)
            ->exists();
    }

    private function belongsToAnyReseller(User $user): bool
    {
        return ResellerUser::query()
            ->where('user_id', $user->id)
            ->where('status', ResellerUserStatus::Active)
            ->exists();
    }
}
