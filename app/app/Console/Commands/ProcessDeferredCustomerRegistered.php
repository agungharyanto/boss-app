<?php

namespace App\Console\Commands;

use App\Enums\CustomerAutoSendResult;
use App\Enums\WhatsappEventType;
use App\Models\CustomerAutoSendDeferral;
use App\Services\Whatsapp\WhatsappGatewayService;
use Illuminate\Console\Command;

/**
 * v0.28.2 (T2) — proses penundaan notifikasi customer_registered (K11).
 * Dijadwalkan ->everyMinute()->withoutOverlapping(). Tiap deferral 'pending'
 * dievaluasi ULANG lewat GERBANG PENUH (buildAndQueueGated fromDeferral=true):
 *  - Queued → gerbang menandai deferral 'sent'.
 *  - Simulated (Mode Uji) → gerbang menandai 'simulated'.
 *  - Deferred (masih transien: di luar jendela / sesi putus / pemutus / plafon)
 *    → tetap 'pending', dicoba lagi menit berikutnya.
 *  - SkippedOptOut / SkippedAutoNotifyOff / SkippedTemplateInactive → batalkan
 *    (pelanggan opt-out / saklar OFF / template nonaktif — tak akan terkirim).
 */
class ProcessDeferredCustomerRegistered extends Command
{
    protected $signature = 'whatsapp:process-deferred-registered {--limit=200 : Maks deferral diproses per run}';

    protected $description = 'Evaluasi ulang gerbang untuk penundaan notifikasi customer_registered (v0.28.2).';

    public function handle(WhatsappGatewayService $gateway): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $deferrals = CustomerAutoSendDeferral::query()
            ->where('status', CustomerAutoSendDeferral::STATUS_PENDING)
            ->with('customer')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $sent = 0;
        $simulated = 0;
        $cancelled = 0;
        $stillPending = 0;

        foreach ($deferrals as $deferral) {
            $customer = $deferral->customer;
            if ($customer === null) {
                // Pelanggan sudah dihapus — batalkan (cascade seharusnya sudah
                // menghapus baris ini; jaga-jaga).
                $deferral->update(['status' => CustomerAutoSendDeferral::STATUS_CANCELLED, 'processed_at' => now()]);
                $cancelled++;

                continue;
            }

            $decision = $gateway->buildAndQueueGated(
                WhatsappEventType::CustomerRegistered,
                $customer,
                null,
                null,
                fromDeferral: true,
            );

            match ($decision->result) {
                // Queued/Simulated: deferral SUDAH ditandai selesai di gerbang.
                CustomerAutoSendResult::Queued => $sent++,
                CustomerAutoSendResult::Simulated => $simulated++,
                CustomerAutoSendResult::Deferred => $stillPending++,
                CustomerAutoSendResult::SkippedOptOut,
                CustomerAutoSendResult::SkippedAutoNotifyOff,
                CustomerAutoSendResult::SkippedTemplateInactive => $this->cancel($deferral, $cancelled),
                default => $stillPending++,
            };
        }

        $this->info("Deferred registered: terkirim={$sent} simulasi={$simulated} dibatalkan={$cancelled} tertunda={$stillPending}.");

        return self::SUCCESS;
    }

    private function cancel(CustomerAutoSendDeferral $deferral, int &$cancelled): void
    {
        $deferral->update([
            'status' => CustomerAutoSendDeferral::STATUS_CANCELLED,
            'processed_at' => now(),
        ]);
        $cancelled++;
    }
}
