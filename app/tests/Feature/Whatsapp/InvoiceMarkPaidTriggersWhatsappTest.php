<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Customer;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappMessageTemplate;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class InvoiceMarkPaidTriggersWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private function pendingInvoiceFor(Customer $customer)
    {
        $subscription = app(SubscriptionService::class)->create($customer, [
            'name' => 'Paket Test',
            'monthly_amount' => 100000,
            'billing_cycle_day' => now()->day,
            'started_at' => now()->subMonth()->toDateString(),
        ]);
        $invoice = app(InvoiceService::class)->generateNextForSubscription($subscription);

        return app(InvoiceService::class)->markPending($invoice);
    }

    public function test_mark_paid_dispatches_payment_received_job_on_the_direct_queue_for_a_direct_customer(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($admin);

        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::PaymentReceived,
        ]);

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $invoice = $this->pendingInvoiceFor($customer);

        app(InvoiceService::class)->markPaid($invoice);

        Bus::assertDispatched(SendWhatsappMessageJob::class, fn ($job) => $job->queue === 'whatsapp-direct');
        $this->assertDatabaseHas('whatsapp_message_logs', [
            'invoice_id' => $invoice->id,
            'event_type' => WhatsappEventType::PaymentReceived->value,
            'reseller_id' => null,
        ]);
    }

    public function test_mark_paid_dispatches_payment_received_job_on_the_resellers_own_queue(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($admin);

        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::PaymentReceived,
        ]);

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $invoice = $this->pendingInvoiceFor($customer);

        app(InvoiceService::class)->markPaid($invoice);

        Bus::assertDispatched(SendWhatsappMessageJob::class, fn ($job) => $job->queue === "whatsapp-{$reseller->id}");
        $this->assertDatabaseHas('whatsapp_message_logs', [
            'invoice_id' => $invoice->id,
            'event_type' => WhatsappEventType::PaymentReceived->value,
            'reseller_id' => $reseller->id,
        ]);
    }

    public function test_mark_paid_does_not_throw_when_no_template_is_configured(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($admin);

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $invoice = $this->pendingInvoiceFor($customer);

        app(InvoiceService::class)->markPaid($invoice);

        Bus::assertNotDispatched(SendWhatsappMessageJob::class);
        $this->assertDatabaseMissing('whatsapp_message_logs', ['invoice_id' => $invoice->id]);
    }
}
