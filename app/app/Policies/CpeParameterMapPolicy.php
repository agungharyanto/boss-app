<?php

namespace App\Policies;

use App\Models\User;

/**
 * Strictly superadmin-only (v0.7.2) — same shape as
 * PaymentGatewaySettingsPolicy/WhatsappGatewaySettingsPolicy: this is
 * platform-level technical config, not a per-reseller concern (unlike
 * NasPolicy/OdpPolicy, there is no reseller_users carve-out here).
 */
class CpeParameterMapPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cpe_parameter_maps.view') || $user->can('cpe_parameter_maps.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('cpe_parameter_maps.view') || $user->can('cpe_parameter_maps.manage');
    }

    public function manage(User $user): bool
    {
        return $user->can('cpe_parameter_maps.manage');
    }
}
