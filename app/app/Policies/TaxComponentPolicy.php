<?php

namespace App\Policies;

use App\Models\TaxComponent;
use App\Models\User;

/**
 * Admin-only for every action — tax_components define the ISP's own
 * regulatory tax catalog, not something a reseller ever edits.
 */
class TaxComponentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tax_components.view');
    }

    public function view(User $user, TaxComponent $taxComponent): bool
    {
        return $user->can('tax_components.view');
    }

    public function create(User $user): bool
    {
        return $user->can('tax_components.manage');
    }

    public function update(User $user, TaxComponent $taxComponent): bool
    {
        return $user->can('tax_components.manage');
    }
}
