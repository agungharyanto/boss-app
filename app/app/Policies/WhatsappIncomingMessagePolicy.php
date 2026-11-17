<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\ResellerUser;
use App\Models\User;
use App\Models\WhatsappIncomingMessage;

/**
 * v0.13.6 — siapa yang boleh melihat media pesan masuk. Pola sama
 * WhatsappMessageLogPolicy (admin/ISP permission → semua; reseller → baris
 * sesinya sendiri), DITAMBAH carve-out D7: pengguna dengan `customers.view`
 * boleh melihat media yang TERTAUT ke pelanggan (tampil read-only di Detail
 * Pelanggan). Direct session (reseller_id null) hanya admin/ISP + jalur
 * customer-link.
 */
class WhatsappIncomingMessagePolicy
{
    public function view(User $user, WhatsappIncomingMessage $message): bool
    {
        if ($user->can('whatsapp_gateway.view') || $user->can('whatsapp_gateway.manage')) {
            return true;
        }

        if ($message->reseller_id !== null && $this->belongsToReseller($user, $message->reseller_id)) {
            return true;
        }

        // D7 — media tertaut pelanggan, terlihat di Detail Pelanggan oleh yang
        // boleh melihat pelanggan.
        return $message->customer_id !== null && $user->can('customers.view');
    }

    private function belongsToReseller(User $user, int $resellerId): bool
    {
        return ResellerUser::query()
            ->where('reseller_id', $resellerId)
            ->where('user_id', $user->id)
            ->where('status', ResellerUserStatus::Active)
            ->exists();
    }
}
