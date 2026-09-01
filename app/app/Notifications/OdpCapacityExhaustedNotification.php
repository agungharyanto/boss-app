<?php

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Notifications\Notification;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 4. Database
 * channel only (no mail/WhatsApp) — this is an internal ops alert for
 * staff holding network_infrastructure.manage, not a customer-facing
 * message, so it deliberately doesn't reuse the WhatsApp Gateway module
 * (that pipeline's whole session/template model is built around
 * customer-facing communication, not staff alerts).
 */
class OdpCapacityExhaustedNotification extends Notification
{
    public function __construct(
        private readonly Customer $customer,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'message' => "Tidak ada ODP dengan port tersedia untuk pelanggan \"{$this->customer->name}\" — semua ODP dalam jangkauan sudah penuh.",
        ];
    }
}
