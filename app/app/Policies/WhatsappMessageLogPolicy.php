<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\ResellerUser;
use App\Models\User;
use App\Models\WhatsappMessageLog;

/**
 * Same shape as WhatsappSessionPolicy/WhatsappMessageTemplatePolicy — admin
 * permission sees every log (including "direct" session logs, reseller_id
 * null); a reseller's own queue (owner OR staff) is viewable/retriable by
 * any active reseller_users membership.
 */
class WhatsappMessageLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('whatsapp_gateway.view') || $user->can('whatsapp_gateway.manage') || $this->belongsToAnyReseller($user);
    }

    public function view(User $user, WhatsappMessageLog $log): bool
    {
        if ($user->can('whatsapp_gateway.view') || $user->can('whatsapp_gateway.manage')) {
            return true;
        }

        return $log->reseller_id !== null && $this->belongsToReseller($user, $log->reseller_id);
    }

    public function retry(User $user, WhatsappMessageLog $log): bool
    {
        if ($user->can('whatsapp_gateway.manage')) {
            return true;
        }

        return $log->reseller_id !== null && $this->belongsToReseller($user, $log->reseller_id);
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
