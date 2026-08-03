<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappGatewaySettings;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SendWhatsappDueRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Guarantee the command's daily_schedule_times gate matches "now",
        // regardless of what wall-clock time the test suite happens to run
        // at.
        WhatsappGatewaySettings::current()->update(['daily_schedule_times' => [now()->format('H:i')]]);
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

    public function test_command_does_nothing_outside_the_configured_schedule_times(): void
    {
        Bus::fake();

        WhatsappGatewaySettings::current()->update(['daily_schedule_times' => ['03:33']]);

        $invoice = $this->invoiceDueOn(now()->toDateString());

        $this->artisan('whatsapp:send-due-reminders')->assertSuccessful();

        $this->assertDatabaseMissing('whatsapp_message_logs', ['invoice_id' => $invoice->id]);
    }
}
