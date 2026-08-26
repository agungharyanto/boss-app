<?php

namespace App\Policies;

use App\Models\User;

/**
 * Strictly superadmin-only (v0.3.5 Fase H) — see
 * RolesAndPermissionsSeeder::seedPaymentGatewaySettingsPermissions() for
 * why this is stricter than invoices.* / billing role access. No model
 * instance involved (singleton, no route-model binding) — same
 * class-level-only shape as InvoicePolicy::manage()/TaxComponentPolicy.
 */
class PaymentGatewaySettingsPolicy
{
    public function view(User $user): bool
    {
        return $user->can('payment_gateway_settings.view');
    }

    public function manage(User $user): bool
    {
        return $user->can('payment_gateway_settings.manage');
    }
}
