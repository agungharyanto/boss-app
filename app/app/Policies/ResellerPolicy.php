<?php

namespace App\Policies;

use App\Enums\ResellerUserRole;
use App\Enums\ResellerUserStatus;
use App\Models\Reseller;
use App\Models\ResellerUser;
use App\Models\User;

class ResellerPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('resellers.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Reseller $reseller): bool
    {
        return $user->can('resellers.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('resellers.manage');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Reseller $reseller): bool
    {
        return $user->can('resellers.manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Reseller $reseller): bool
    {
        return $user->can('resellers.manage');
    }

    /**
     * Managing reseller_users (list/attach/detach staff) is allowed for ISP
     * admins, plus the reseller's own active owner — but not plain staff.
     */
    public function manageUsers(User $user, Reseller $reseller): bool
    {
        if ($user->can('resellers.manage')) {
            return true;
        }

        return ResellerUser::query()
            ->where('reseller_id', $reseller->id)
            ->where('user_id', $user->id)
            ->where('role', ResellerUserRole::Owner)
            ->where('status', ResellerUserStatus::Active)
            ->exists();
    }
}
