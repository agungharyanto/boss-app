<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Enums\WhatsappEventType;
use App\Models\Customer;
use App\Models\WhatsappMessageLog;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Whatsapp\WhatsappGatewayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled ->dailyAt('08:00') (see routes/console.php). No "already
 * suspended reminded" cap beyond one-per-day — a customer still suspended
 * tomorrow gets another reminder tomorrow. Stops naturally the moment this
 * query no longer finds them (status changed away from Suspend), no manual
 * cap/counter needed.
 */
class SendWhatsappSuspendedReminders extends Command
{
    protected $signature = 'whatsapp:send-suspended-reminders';

    protected $description = 'Kirim pengingat WhatsApp harian untuk pelanggan berstatus suspend';

    public function handle(WhatsappGatewayService $service): int
    {
        // v0.13.5.1.2 — early-exit per tenant: lewati tenant yang saklar
        // "Notifikasi Otomatis ke Pelanggan"-nya OFF SEBELUM mengiterasi
        // pelanggan. Guard otoritatif tetap di buildAndQueue().
        $disabledTenantIds = WorkOrderDispatchSettings::query()
            ->where('customer_auto_notify_enabled', false)
            ->pluck('tenant_id')
            ->all();

        if ($disabledTenantIds !== []) {
            Log::info('SendWhatsappSuspendedReminders: lewati tenant dengan saklar notifikasi pelanggan mati', [
                'tenants' => $disabledTenantIds,
            ]);
        }

        $customers = Customer::withoutGlobalScopes()
            ->where('status', CustomerStatus::Suspend->value)
            ->when($disabledTenantIds !== [], fn ($query) => $query->whereNotIn('tenant_id', $disabledTenantIds))
            ->get();

        $sent = 0;

        foreach ($customers as $customer) {
            $alreadySentToday = WhatsappMessageLog::withoutGlobalScopes()
                ->where('customer_id', $customer->id)
                ->where('event_type', WhatsappEventType::CustomerSuspendedReminder->value)
                ->whereDate('created_at', now()->toDateString())
                ->exists();

            if ($alreadySentToday) {
                continue;
            }

            $service->buildAndQueue(WhatsappEventType::CustomerSuspendedReminder, $customer);
            $sent++;
        }

        $this->info("Selesai. {$sent} pengingat suspend diantrikan dari {$customers->count()} pelanggan berstatus suspend.");

        return self::SUCCESS;
    }
}
