<?php

namespace App\Policies;

use App\Enums\ResellerUserRole;
use App\Enums\ResellerUserStatus;
use App\Models\Reseller;
use App\Models\ResellerTaxPolicy;
use App\Models\ResellerUser;
use App\Models\User;

/**
 * Admin (reseller_tax_policies.manage/.view) gets full access, including
 * the direct-retail policies (reseller_id null) — those never belong to any
 * reseller, so a reseller owner/staff can never see or touch them. For
 * reseller-specific rows: owner can create/update policies for their OWN
 * reseller only; staff is read-only (create/update stay admin+owner-only).
 */
class ResellerTaxPolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('reseller_tax_policies.view') || $this->belongsToAnyReseller($user);
    }

    public function view(User $user, ResellerTaxPolicy $policy): bool
    {
        if ($user->can('reseller_tax_policies.view')) {
            return true;
        }

        if ($policy->reseller_id === null) {
            return false;
        }

        return $this->belongsToReseller($user, $policy->reseller_id);
    }

    /**
     * $reseller is extra context for a not-yet-existing policy — pass it via
     * $this->authorize('create', [ResellerTaxPolicy::class, $reseller]).
     * $reseller = null means "set a direct-retail policy", which only an
     * admin may do.
     */
    public function create(User $user, ?Reseller $reseller = null): bool
    {
        if ($user->can('reseller_tax_policies.manage')) {
            return true;
        }

        if ($reseller === null) {
            return false;
        }

        return $this->isOwnerOf($user, $reseller->id);
    }

    public function update(User $user, ResellerTaxPolicy $policy): bool
    {
        if ($user->can('reseller_tax_policies.manage')) {
            return true;
        }

        if ($policy->reseller_id === null) {
            return false;
        }

        return $this->isOwnerOf($user, $policy->reseller_id);
    }

    private function isOwnerOf(User $user, int $resellerId): bool
    {
        return ResellerUser::query()
            ->where('reseller_id', $resellerId)
            ->where('user_id', $user->id)
            ->where('role', ResellerUserRole::Owner)
            ->where('status', ResellerUserStatus::Active)
            ->exists();
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
