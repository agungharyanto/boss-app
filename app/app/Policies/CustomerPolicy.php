<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\Customer;
use App\Models\ResellerUser;
use App\Models\User;

class CustomerPolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * Reseller owner/staff are admitted here too (v0.3.2) — they have no
     * customers.* permission at all (that permission set is for internal ISP
     * staff roles), but App\Models\Scopes\ResellerScope already narrows any
     * listing query down to their own reseller once
     * App\Http\Middleware\ResolveResellerContext resolves it, so this gate
     * just needs to admit them past "viewAny" itself.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('customers.view') || $this->belongsToAnyReseller($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Customer $customer): bool
    {
        return $user->can('customers.view') || $this->belongsToSameReseller($user, $customer);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('customers.manage') || $this->belongsToAnyReseller($user);
    }

    /**
     * Determine whether the user can update the model, including its
     * lifecycle status.
     */
    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customers.manage') || $this->belongsToSameReseller($user, $customer);
    }

    private function belongsToSameReseller(User $user, Customer $customer): bool
    {
        if ($customer->reseller_id === null) {
            return false;
        }

        return ResellerUser::query()
            ->where('reseller_id', $customer->reseller_id)
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
