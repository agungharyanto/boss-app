<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\ResellerUser;
use App\Models\User;
use App\Models\WhatsappSession;

/**
 * Admin (whatsapp_gateway.manage/.view permission) gets full VIEW access to
 * every session, including reseller-owned ones (needed for the Overview
 * Sesi table). MANAGE (create/refresh-QR) is narrower and mutually
 * exclusive by ownership, confirmed explicitly during the session-creation
 * bugfix: admin may only manage the reseller_id-null "direct" session — a
 * reseller's own session is manageable ONLY by that reseller's active
 * reseller_users membership (owner OR staff — unlike ResellerTaxPolicyPolicy,
 * this module doesn't restrict staff to read-only), never by an admin, even
 * though the admin can still see its status in the overview.
 */
class WhatsappSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('whatsapp_gateway.view') || $this->belongsToAnyReseller($user);
    }

    public function view(User $user, WhatsappSession $session): bool
    {
        if ($user->can('whatsapp_gateway.view') || $user->can('whatsapp_gateway.manage')) {
            return true;
        }

        return $session->reseller_id !== null && $this->belongsToReseller($user, $session->reseller_id);
    }

    public function manage(User $user, WhatsappSession $session): bool
    {
        if ($session->reseller_id === null) {
            return $user->can('whatsapp_gateway.manage');
        }

        return $this->belongsToReseller($user, $session->reseller_id);
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
