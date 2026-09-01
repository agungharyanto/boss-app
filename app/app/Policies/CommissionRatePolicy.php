<?php

namespace App\Policies;

use App\Models\User;

/**
 * v0.9.3 — tier-admin-only (superadmin/administrator), bentuk sama dengan
 * BandwidthProfilePolicy/ReferrerPolicy: CommissionRate tidak punya
 * reseller_id sama sekali (tenant-level, FK ke ppp_packages), jadi tidak
 * ada carve-out keanggotaan reseller_users seperti NasPolicy/OdpPolicy.
 * Isolasi lintas-tenant sudah ditangani satu lapis di bawah policy ini
 * oleh global scope BelongsToTenant milik CommissionRate sendiri.
 */
class CommissionRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('commission_rates.view') || $user->can('commission_rates.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('commission_rates.view') || $user->can('commission_rates.manage');
    }

    public function manage(User $user): bool
    {
        return $user->can('commission_rates.manage');
    }
}
