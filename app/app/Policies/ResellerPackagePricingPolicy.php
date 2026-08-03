<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\ResellerPackagePricing;
use App\Models\ResellerUser;
use App\Models\User;

class ResellerPackagePricingPolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * Reseller owner/staff are allowed here too — App\Models\Scopes\ResellerScope
     * (via App\Support\ResellerContext) already narrows any listing query down
     * to their own reseller, so "viewAny" just needs to admit them past the
     * gate at all.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('resellers.view') || $this->belongsToAnyReseller($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ResellerPackagePricing $pricing): bool
    {
        return $user->can('resellers.view') || $this->belongsToReseller($user, $pricing->reseller_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('resellers.manage') || $this->belongsToAnyReseller($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ResellerPackagePricing $pricing): bool
    {
        return $user->can('resellers.manage') || $this->belongsToReseller($user, $pricing->reseller_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ResellerPackagePricing $pricing): bool
    {
        return $user->can('resellers.manage') || $this->belongsToReseller($user, $pricing->reseller_id);
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
