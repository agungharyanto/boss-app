<?php

namespace App\Policies;

use App\Models\User;

/**
 * v0.16.0 Core Network Infrastructure Management — tier-admin-only, same
 * shape as BandwidthProfilePolicy (single permission pair,
 * network_infrastructure.view/.manage seeded in Langkah 2). No reseller
 * carve-out yet — see RolesAndPermissionsSeeder::seedFiberNetworkPermissions()'s
 * own docblock for why that's a deliberate, revisitable decision, not an
 * oversight.
 */
class FiberNodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('network_infrastructure.view') || $user->can('network_infrastructure.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('network_infrastructure.view') || $user->can('network_infrastructure.manage');
    }

    public function manage(User $user): bool
    {
        return $user->can('network_infrastructure.manage');
    }
}
