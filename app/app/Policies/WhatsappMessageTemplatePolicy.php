<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\ResellerUser;
use App\Models\User;
use App\Models\WhatsappMessageTemplate;

/**
 * Same shape as WhatsappSessionPolicy: admin permission gets everything
 * including default (reseller_id null) ISP-level templates; a reseller's
 * own override (owner OR staff) is viewable/manageable by any active
 * reseller_users membership.
 */
class WhatsappMessageTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('whatsapp_gateway.view') || $user->can('whatsapp_gateway.manage') || $this->belongsToAnyReseller($user);
    }

    public function view(User $user, WhatsappMessageTemplate $template): bool
    {
        if ($user->can('whatsapp_gateway.view') || $user->can('whatsapp_gateway.manage')) {
            return true;
        }

        return $template->reseller_id !== null && $this->belongsToReseller($user, $template->reseller_id);
    }

    public function manage(User $user, WhatsappMessageTemplate $template): bool
    {
        if ($user->can('whatsapp_gateway.manage')) {
            return true;
        }

        return $template->reseller_id !== null && $this->belongsToReseller($user, $template->reseller_id);
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
