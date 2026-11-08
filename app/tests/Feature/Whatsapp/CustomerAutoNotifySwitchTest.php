<?php

namespace Tests\Feature\Whatsapp;

use App\Console\Commands\SendWhatsappSuspendedReminders;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\WhatsappEventType;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Referrer;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WorkOrderDispatchSettings;
use App\Services\InvoiceService;
use App\Services\Whatsapp\WhatsappGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * v0.13.5.1.2 — saklar per-tenant "Notifikasi Otomatis ke Pelanggan".
 * Titik penegakan tunggal: WhatsappGatewayService::buildAndQueue() (4 event
 * pelanggan). Kelompok (B) — buildAndQueueForRecipient/ForReferrer — tidak
 * memeriksa saklar ini. Nol kirim nyata (Queue::fake).
 */
class CustomerAutoNotifySwitchTest extends TestCase
{
    use RefreshDatabase;

    private function template(int $tenantId, WhatsappEventType $event, ?int $resellerId = null): void
    {
        WhatsappMessageTemplate::create([
            'tenant_id' => $tenantId,
            'reseller_id' => $resellerId,
            'event_type' => $event->value,
            'content' => 'Halo (uji) — '.$event->value,
            'is_active' => true,
        ]);
    }

    private function customer(int $tenantId): Customer
    {
        return Customer::factory()->create(['tenant_id' => $tenantId, 'reseller_id' => null]);
    }

    private function switchOff(int $tenantId): void
    {
        WorkOrderDispatchSettings::forTenant($tenantId)->update(['customer_auto_notify_enabled' => false]);
    }

    // ── Guard di buildAndQueue() ─────────────────────────────────────────

    public function test_switch_off_skips_customer_event_no_log_no_job(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id, WhatsappEventType::CustomerRegistered);
        $customer = $this->customer($tenant->id);
        $this->switchOff($tenant->id);

        $result = app(WhatsappGatewayService::class)
            ->buildAndQueue(WhatsappEventType::CustomerRegistered, $customer);

        $this->assertNull($result);
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_switch_on_sends_normally(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id, WhatsappEventType::CustomerRegistered);
        $customer = $this->customer($tenant->id);
        // Default baris = ON (forTenant default true).
        WorkOrderDispatchSettings::forTenant($tenant->id);

        $result = app(WhatsappGatewayService::class)
            ->buildAndQueue(WhatsappEventType::CustomerRegistered, $customer);

        $this->assertNotNull($result);
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::CustomerRegistered->value)->count());
        Queue::assertPushed(SendWhatsappMessageJob::class);
    }

    public function test_no_settings_row_defaults_to_on(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id, WhatsappEventType::CustomerRegistered);
        $customer = $this->customer($tenant->id);
        // SENGAJA tidak membuat baris settings sama sekali → dianggap ON.

        $result = app(WhatsappGatewayService::class)
            ->buildAndQueue(WhatsappEventType::CustomerRegistered, $customer);

        $this->assertNotNull($result);
        Queue::assertPushed(SendWhatsappMessageJob::class);
    }

    public function test_all_four_customer_events_skipped_when_off(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $customer = $this->customer($tenant->id);
        $this->switchOff($tenant->id);

        foreach ([
            WhatsappEventType::InvoiceDueReminder,
            WhatsappEventType::PaymentReceived,
            WhatsappEventType::CustomerRegistered,
            WhatsappEventType::CustomerSuspendedReminder,
        ] as $event) {
            $this->assertNull(app(WhatsappGatewayService::class)->buildAndQueue($event, $customer));
        }

        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    // ── Pemanggil tidak gagal / tidak menulis state saat dilewati ────────

    public function test_markpaid_still_succeeds_when_switch_off_and_no_payment_received_log(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $customer = $this->customer($tenant->id);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'reseller_id' => null,
            'status' => InvoiceStatus::Pending->value,
        ]);
        $this->switchOff($tenant->id);

        $result = app(InvoiceService::class)->markPaid($invoice);

        // Pembayaran TETAP sukses meski WA dilewati.
        $this->assertSame(InvoiceStatus::Paid, $result->status);
        $this->assertNotNull($result->paid_at);
        // Tidak ada log payment_received (dilewati saklar).
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::PaymentReceived->value)->count());
    }

    // ── Command terjadwal early-exit ─────────────────────────────────────

    public function test_suspended_reminder_command_skips_off_tenant_but_sends_on_tenant(): void
    {
        Queue::fake();

        $offTenant = Tenant::factory()->create();
        $this->template($offTenant->id, WhatsappEventType::CustomerSuspendedReminder);
        $offCustomer = Customer::factory()->create(['tenant_id' => $offTenant->id, 'reseller_id' => null, 'status' => CustomerStatus::Suspend->value]);
        $this->switchOff($offTenant->id);

        $onTenant = Tenant::factory()->create();
        $this->template($onTenant->id, WhatsappEventType::CustomerSuspendedReminder);
        $onCustomer = Customer::factory()->create(['tenant_id' => $onTenant->id, 'reseller_id' => null, 'status' => CustomerStatus::Suspend->value]);
        WorkOrderDispatchSettings::forTenant($onTenant->id); // default ON

        $this->artisan(SendWhatsappSuspendedReminders::class)->assertExitCode(0);

        // Tenant OFF: nol log untuk pelanggannya. Tenant ON: 1 log.
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->where('customer_id', $offCustomer->id)->count());
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('customer_id', $onCustomer->id)->count());
    }

    // ── Kelompok (B) TIDAK terpengaruh saklar ────────────────────────────

    public function test_group_b_recipient_still_sends_when_customer_switch_off(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $this->switchOff($tenant->id);

        // Event teknisi (B) lewat buildAndQueueForRecipient — TIDAK cek saklar.
        $this->template($tenant->id, WhatsappEventType::WorkOrderDispatched);
        $log = app(WhatsappGatewayService::class)->buildAndQueueForRecipient(
            WhatsappEventType::WorkOrderDispatched,
            $tenant->id,
            '6287884374939',
            ['technician_name' => 'TEST Teknisi'],
        );

        $this->assertNotNull($log);
        Queue::assertPushed(SendWhatsappMessageJob::class);
    }

    public function test_group_b_referrer_otp_still_sends_when_customer_switch_off(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $this->switchOff($tenant->id);
        $this->template($tenant->id, WhatsappEventType::ReferrerActionOtp);
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id, 'phone' => '6287884374939', 'is_active' => true]);

        $log = app(WhatsappGatewayService::class)->buildAndQueueForReferrer(
            WhatsappEventType::ReferrerActionOtp,
            $referrer,
            ['otp_code' => '123456'],
        );

        $this->assertNotNull($log);
        Queue::assertPushed(SendWhatsappMessageJob::class);
    }

    // ── Migrasi default ──────────────────────────────────────────────────

    public function test_existing_row_defaults_to_enabled(): void
    {
        $tenant = Tenant::factory()->create();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);

        $this->assertTrue($settings->customer_auto_notify_enabled);
    }
}
