<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\WhatsappEventType;
use App\Models\Invoice;
use App\Models\WhatsappGatewaySettings;
use App\Models\WhatsappMessageLog;
use App\Services\Whatsapp\WhatsappGatewayService;
use Illuminate\Console\Command;

/**
 * Scheduled ->everyMinute() (see routes/console.php) but only actually does
 * work when the current time matches one of the admin-configurable
 * daily_schedule_times entries — the schedule itself lives in
 * whatsapp_gateway_settings, not a static cron expression, so the command
 * has to poll and self-gate rather than being scheduled at a fixed time.
 */
class SendWhatsappDueReminders extends Command
{
    protected $signature = 'whatsapp:send-due-reminders';

    protected $description = 'Kirim pengingat WhatsApp H-5 dan H-0 untuk invoice yang belum dibayar (sesuai jadwal daily_schedule_times)';

    public function handle(WhatsappGatewayService $service): int
    {
        if (! $this->isScheduledNow()) {
            return self::SUCCESS;
        }

        // status=Pending only (not Overdue) is what makes "no reminder past
        // H-0" true almost by construction: MarkOverdueInvoices only flips
        // an invoice to Overdue the day AFTER due_date passes, so a
        // Pending invoice can only be due today or in the future here.
        $invoices = Invoice::withoutGlobalScopes()
            ->where('status', InvoiceStatus::Pending->value)
            ->where(function ($query) {
                $query->whereDate('due_date', now()->toDateString())
                    ->orWhereDate('due_date', now()->copy()->addDays(5)->toDateString());
            })
            ->with(['customer', 'subscription'])
            ->get();

        $sent = 0;

        foreach ($invoices as $invoice) {
            if ($invoice->customer === null) {
                continue;
            }

            $alreadySentToday = WhatsappMessageLog::withoutGlobalScopes()
                ->where('invoice_id', $invoice->id)
                ->where('event_type', WhatsappEventType::InvoiceDueReminder->value)
                ->whereDate('created_at', now()->toDateString())
                ->exists();

            if ($alreadySentToday) {
                continue;
            }

            $service->buildAndQueue(WhatsappEventType::InvoiceDueReminder, $invoice->customer, $invoice);
            $sent++;
        }

        $this->info("Selesai. {$sent} pengingat due-date diantrikan dari {$invoices->count()} invoice yang cocok.");

        return self::SUCCESS;
    }

    private function isScheduledNow(): bool
    {
        $times = WhatsappGatewaySettings::current()->daily_schedule_times ?? ['08:00', '20:00'];

        return in_array(now()->format('H:i'), $times, true);
    }
}
