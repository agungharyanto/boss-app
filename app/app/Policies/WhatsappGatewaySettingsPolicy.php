<?php

namespace App\Policies;

use App\Models\User;

/**
 * Strictly superadmin-only, same posture as PaymentGatewaySettingsPolicy —
 * the global rate-limit policy (delay/batch size/daily schedule) applies to
 * every session platform-wide, so no reseller gets a say in it this sprint
 * (see docs/ROADMAP.md deferred: "rate limit setting per-reseller").
 */
class WhatsappGatewaySettingsPolicy
{
    public function view(User $user): bool
    {
        return $user->can('whatsapp_gateway_settings.view') || $user->can('whatsapp_gateway_settings.manage');
    }

    public function manage(User $user): bool
    {
        return $user->can('whatsapp_gateway_settings.manage');
    }
}
