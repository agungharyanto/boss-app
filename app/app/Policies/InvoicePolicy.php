<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\Invoice;
use App\Models\ResellerUser;
use App\Models\User;

/**
 * Same posture as SubscriptionPolicy: admin-only for generation/status
 * transitions (invoices.manage), reseller owner/staff read-only on their
 * own reseller's invoices.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view') || $this->belongsToAnyReseller($user);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->can('invoices.view')) {
            return true;
        }

        if ($invoice->reseller_id === null) {
            return false;
        }

        return $this->belongsToReseller($user, $invoice->reseller_id);
    }

    public function manage(User $user): bool
    {
        return $user->can('invoices.manage');
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
