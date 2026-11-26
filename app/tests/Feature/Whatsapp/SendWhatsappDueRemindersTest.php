<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Enums\WhatsappSessionStatus;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappGatewaySettings;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SendWhatsappDueRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // v0.28.2 — gerbang menuntut dalam jendela jam; kunci waktu di 09:00 WIB
        // (deterministik, tak bergantung jam CI).
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 9, 0, 0, 'Asia/Jakarta'));

        // Guarantee the command's daily_schedule_times gate matches "now".
        WhatsappGatewaySettings::current()->update(['daily_schedule_times' => [now()->format('H:i')]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoiceDueOn(string $dueDate): Invoice
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($admin);

        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::InvoiceDueReminder,
        ]);

        // v0.28.2 — gerbang menuntut sesi tujuan Connected.
        WhatsappSession::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'status' => WhatsappSessionStatus::Connected,
        ]);

        // v0.28.6 — command kini pembungkus orkestrator (enumerasi tenant dari
        // work_order_dispatch_settings; default saklar ON, jendela 08:00–20:00,
        // due_reminder_days [5,0]).
        WorkOrderDispatchSettings::forTenant($tenant->id);

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = app(SubscriptionService::class)->create($customer, [
            'name' => 'Paket Test',
            'monthly_amount' => 100000,
            'billing_cycle_day' => now()->day,
            'started_at' => now()->subMonth()->toDateString(),
        ]);
        $invoice = app(InvoiceService::class)->generateNextForSubscription($subscription);
        $invoice = app(InvoiceService::class)->markPending($invoice);
        $invoice->update(['due_date' => $dueDate]);

        return $invoice->fresh();
    }

    public function test_invoice_due_today_queues_a_reminder(): void
    {
        Bus::fake();

        $invoice = $this->invoiceDueOn(now()->toDateString());

        $this->artisan('whatsapp:send-due-reminders')->assertSuccessful();

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'invoice_id' => $invoice->id,
            'event_type' => WhatsappEventType::InvoiceDueReminder->value,
        ]);
        Bus::assertDispatched(SendWhatsappMessageJob::class);
    }

    public function test_invoice_due_in_five_days_queues_a_reminder(): void
    {
        Bus::fake();

        $invoice = $this->invoiceDueOn(now()->copy()->addDays(5)->toDateString());

        $this->artisan('whatsapp:send-due-reminders')->assertSuccessful();

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'invoice_id' => $invoice->id,
            'event_type' => WhatsappEventType::InvoiceDueReminder->value,
        ]);
    }

    public function test_running_the_command_twice_the_same_day_does_not_duplicate_the_reminder(): void
    {
        Bus::fake();

        $invoice = $this->invoiceDueOn(now()->toDateString());

        $this->artisan('whatsapp:send-due-reminders')->assertSuccessful();
        $this->artisan('whatsapp:send-due-reminders')->assertSuccessful();

        $this->assertSame(
            1,
            WhatsappMessageLog::where('invoice_id', $invoice->id)
                ->where('event_type', WhatsappEventType::InvoiceDueReminder->value)
                ->count()
        );
    }

    public function test_overdue_invoice_does_not_trigger_a_reminder(): void
    {
        Bus::fake();

        // due_date in the past + status transitioned to Overdue (same path
        // MarkOverdueInvoices uses) — the command only ever queries
        // status=Pending, so this must never match.
        $invoice = $this->invoiceDueOn(now()->subDays(3)->toDateString());
        app(InvoiceService::class)->markOverdue($invoice->fresh());

        $this->artisan('whatsapp:send-due-reminders')->assertSuccessful();

        $this->assertDatabaseMissing('whatsapp_message_logs', [
            'invoice_id' => $invoice->id,
            'event_type' => WhatsappEventType::InvoiceDueReminder->value,
        ]);
    }

    public function test_manual_command_ignores_daily_schedule_times_and_still_queues(): void
    {
        // v0.28.6 — command kini pembungkus orkestrator manual-only; gate
        // isScheduledNow (daily_schedule_times) LAMA dihapus. Jalur pelanggan
        // dijadwalkan lewat whatsapp:auto-send-dispatch (everyMinute), bukan
        // daily_schedule_times. Jadi menjalankan command secara manual TETAP
        // mengantre walau daily_schedule_times tak cocok jam sekarang — asal
        // dalam jendela jam (09:00 WIB, default 08:00–20:00).
        Bus::fake();

        WhatsappGatewaySettings::current()->update(['daily_schedule_times' => ['03:33']]);

        $invoice = $this->invoiceDueOn(now()->toDateString());

        $this->artisan('whatsapp:send-due-reminders')->assertSuccessful();

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'invoice_id' => $invoice->id,
            'event_type' => WhatsappEventType::InvoiceDueReminder->value,
        ]);
    }
}
