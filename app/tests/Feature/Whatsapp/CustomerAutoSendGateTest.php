<?php

namespace Tests\Feature\Whatsapp;

use App\Console\Commands\ProcessDeferredCustomerRegistered;
use App\Console\Commands\SendWhatsappDueReminders;
use App\Console\Commands\WhatsappAutoSendResume;
use App\Enums\CustomerAutoSendResult;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\WhatsappEventType;
use App\Enums\WhatsappMessageStatus;
use App\Enums\WhatsappSessionStatus;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Customer;
use App\Models\CustomerAutoSendBreaker;
use App\Models\CustomerAutoSendDecisionLog;
use App\Models\CustomerAutoSendDeferral;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappGatewaySettings;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Whatsapp\CustomerAutoSendGate;
use App\Services\Whatsapp\WhatsappGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * v0.28.2 (T2) — CustomerAutoSendGate: Mode Uji semua jalur (simulasi), plafon
 * gabungan, jendela, sesi, pemutus, deferral, dua aturan tambahan Agung.
 */
class CustomerAutoSendGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // Dalam jendela (09:00 WIB) kecuali test yang override.
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 9, 0, 0, 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gate(): CustomerAutoSendGate
    {
        return app(CustomerAutoSendGate::class);
    }

    private function gateway(): WhatsappGatewayService
    {
        return app(WhatsappGatewayService::class);
    }

    private function tenantReady(array $overrides = []): Tenant
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenant->id)->update(array_merge([
            'customer_auto_notify_enabled' => true,
            'invoice_pdf_test_mode_enabled' => false,
        ], $overrides));
        WhatsappSession::factory()->create([
            'tenant_id' => $tenant->id, 'reseller_id' => null, 'status' => WhatsappSessionStatus::Connected,
        ]);
        foreach ([
            WhatsappEventType::InvoiceIssued,
            WhatsappEventType::InvoiceDueReminder,
            WhatsappEventType::CustomerSuspendedReminder,
            WhatsappEventType::CustomerRegistered,
        ] as $event) {
            WhatsappMessageTemplate::create([
                'tenant_id' => $tenant->id, 'reseller_id' => null,
                'event_type' => $event->value, 'content' => 'uji '.$event->value, 'is_active' => true,
            ]);
        }

        return $tenant;
    }

    private function customer(Tenant $tenant, array $overrides = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'tenant_id' => $tenant->id, 'reseller_id' => null, 'status' => CustomerStatus::Aktif,
            'phone_number' => '6281234567890',
        ], $overrides));
    }

    private function pendingInvoice(Tenant $tenant, Customer $customer): Invoice
    {
        $sub = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);

        return Invoice::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'subscription_id' => $sub->id,
            'reseller_id' => null, 'status' => InvoiceStatus::Pending->value,
            'due_date' => now()->toDateString(), 'generated_at' => now()->subHours(2),
        ]);
    }

    // ── Mode Uji = SIMULASI untuk 3 jalur, SkippedTestMode untuk invoice ──

    public function test_mode_uji_simulates_reminder_suspend_registered_no_log_no_job(): void
    {
        $tenant = $this->tenantReady(['invoice_pdf_test_mode_enabled' => true]);
        $customer = $this->customer($tenant);
        $invoice = $this->pendingInvoice($tenant, $customer);

        $due = $this->gateway()->buildAndQueueGated(WhatsappEventType::InvoiceDueReminder, $customer, $invoice);
        $susp = $this->gateway()->buildAndQueueGated(WhatsappEventType::CustomerSuspendedReminder, $customer);
        $reg = $this->gateway()->buildAndQueueGated(WhatsappEventType::CustomerRegistered, $customer);

        $this->assertSame(CustomerAutoSendResult::Simulated, $due->result);
        $this->assertSame(CustomerAutoSendResult::Simulated, $susp->result);
        $this->assertSame(CustomerAutoSendResult::Simulated, $reg->result);
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
        $this->assertSame(3, CustomerAutoSendDecisionLog::where('result', 'simulated')->count());
    }

    /**
     * Pra-rilis item 2: cek template SEBELUM Mode Uji. Template due NONAKTIF +
     * Mode Uji ON → SkippedTemplateInactive, BUKAN Simulated (dry-run tak boleh
     * mencatat "akan dikirim" untuk event yang templatenya mati).
     */
    public function test_mode_uji_with_inactive_template_is_skipped_template_inactive_not_simulated(): void
    {
        $tenant = $this->tenantReady(['invoice_pdf_test_mode_enabled' => true]);
        WhatsappMessageTemplate::where('tenant_id', $tenant->id)
            ->where('event_type', WhatsappEventType::InvoiceDueReminder->value)
            ->update(['is_active' => false]);
        $customer = $this->customer($tenant);
        $invoice = $this->pendingInvoice($tenant, $customer);

        $decision = $this->gateway()->buildAndQueueGated(WhatsappEventType::InvoiceDueReminder, $customer, $invoice);

        $this->assertSame(CustomerAutoSendResult::SkippedTemplateInactive, $decision->result);
        $this->assertSame(0, CustomerAutoSendDecisionLog::where('result', 'simulated')->count());
        $this->assertSame(1, CustomerAutoSendDecisionLog::where('result', 'skipped_template_inactive')->count());
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_mode_uji_invoice_issued_is_skipped_test_mode_not_simulated(): void
    {
        $tenant = $this->tenantReady(['invoice_pdf_test_mode_enabled' => true]);
        $customer = $this->customer($tenant);
        $invoice = $this->pendingInvoice($tenant, $customer);

        $decision = $this->gateway()->buildAndQueueGated(WhatsappEventType::InvoiceIssued, $customer, $invoice);

        $this->assertSame(CustomerAutoSendResult::SkippedTestMode, $decision->result);
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());
        $this->assertSame(1, CustomerAutoSendDecisionLog::where('result', 'skipped_test_mode')->count());
    }

    // ── Sesi bukan Connected ─────────────────────────────────────────────

    public function test_session_down_blocks_and_records_no_send(): void
    {
        $tenant = $this->tenantReady();
        WhatsappSession::query()->where('tenant_id', $tenant->id)->update(['status' => WhatsappSessionStatus::LoggedOut]);
        $customer = $this->customer($tenant);
        $invoice = $this->pendingInvoice($tenant, $customer);

        $decision = $this->gateway()->buildAndQueueGated(WhatsappEventType::InvoiceDueReminder, $customer, $invoice);

        $this->assertSame(CustomerAutoSendResult::SessionDown, $decision->result);
        $this->assertTrue($decision->isRunStop());
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    // ── Jendela jam ──────────────────────────────────────────────────────

    public function test_outside_window_blocks_scheduled_path(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 21, 0, 0, 'Asia/Jakarta')); // 21:00 WIB
        $tenant = $this->tenantReady();
        $customer = $this->customer($tenant);
        $invoice = $this->pendingInvoice($tenant, $customer);

        $decision = $this->gateway()->buildAndQueueGated(WhatsappEventType::InvoiceDueReminder, $customer, $invoice);

        $this->assertSame(CustomerAutoSendResult::OutsideWindow, $decision->result);
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());
    }

    // ── Plafon GABUNGAN lintas jalur ─────────────────────────────────────

    public function test_combined_cap_across_paths_stops_run(): void
    {
        $tenant = $this->tenantReady(['customer_auto_send_daily_cap' => 2]);
        $c1 = $this->customer($tenant);
        $c2 = $this->customer($tenant);
        $c3 = $this->customer($tenant);
        $i1 = $this->pendingInvoice($tenant, $c1);

        // 1 lewat due reminder + 1 lewat suspend = cap 2 tercapai; registered ke-3 → CapReached.
        $this->assertSame(CustomerAutoSendResult::Queued, $this->gateway()->buildAndQueueGated(WhatsappEventType::InvoiceDueReminder, $c1, $i1)->result);
        $this->assertSame(CustomerAutoSendResult::Queued, $this->gateway()->buildAndQueueGated(WhatsappEventType::CustomerSuspendedReminder, $c2)->result);
        $third = $this->gateway()->buildAndQueueGated(WhatsappEventType::CustomerRegistered, $c3);

        // registered + CapReached (transien) → Deferred.
        $this->assertSame(CustomerAutoSendResult::Deferred, $third->result);
        $this->assertSame(2, WhatsappMessageLog::withoutGlobalScopes()->count());
    }

    // ── Opt-out (v0.28.1) tetap diblokir lewat gerbang ───────────────────

    public function test_opt_out_still_blocks_via_gate(): void
    {
        $tenant = $this->tenantReady();
        $customer = $this->customer($tenant, ['auto_notify_opt_out' => true]);
        $invoice = $this->pendingInvoice($tenant, $customer);

        $decision = $this->gateway()->buildAndQueueGated(WhatsappEventType::InvoiceDueReminder, $customer, $invoice);

        $this->assertSame(CustomerAutoSendResult::SkippedOptOut, $decision->result);
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());
    }

    // ── Pemutus ──────────────────────────────────────────────────────────

    private function autoLog(Tenant $tenant): WhatsappMessageLog
    {
        return WhatsappMessageLog::create([
            'tenant_id' => $tenant->id, 'reseller_id' => null, 'customer_id' => null, 'invoice_id' => null,
            'phone_number' => '6281234567890', 'event_type' => WhatsappEventType::InvoiceIssued,
            'rendered_content' => 'x', 'is_test' => false, 'status' => WhatsappMessageStatus::Failed, 'queued_at' => now(),
        ]);
    }

    public function test_breaker_opens_after_threshold_failures_and_resets_on_success(): void
    {
        $tenant = $this->tenantReady(['customer_auto_send_breaker_threshold' => 10]);
        $log = $this->autoLog($tenant);

        for ($i = 0; $i < 9; $i++) {
            $this->gate()->recordOutcome($log, false);
        }
        $this->assertFalse($this->gate()->breakerOpen($tenant->id, null));

        $this->gate()->recordOutcome($log, false); // ke-10
        $this->assertTrue($this->gate()->breakerOpen($tenant->id, null));

        // Sukses mereset counter (walau pemutus hanya ditutup via sesi/resume).
        $breaker = CustomerAutoSendBreaker::where('tenant_id', $tenant->id)->first();
        $breaker->update(['is_open' => false]);
        $this->gate()->recordOutcome($log, false);
        $this->gate()->recordOutcome($log, true);
        $this->assertSame(0, CustomerAutoSendBreaker::where('tenant_id', $tenant->id)->first()->consecutive_failures);
    }

    public function test_breaker_ignores_non_automatic_failures(): void
    {
        $tenant = $this->tenantReady();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $make = function (WhatsappEventType $event, ?int $sentBy, bool $isTest) use ($tenant) {
            return WhatsappMessageLog::create([
                'tenant_id' => $tenant->id, 'reseller_id' => null, 'customer_id' => null, 'invoice_id' => null,
                'phone_number' => '6281234567890', 'event_type' => $event,
                'sent_by_user_id' => $sentBy, 'is_test' => $isTest,
                'rendered_content' => 'x', 'status' => WhatsappMessageStatus::Failed, 'queued_at' => now(),
            ]);
        };

        // Kegagalan: manual (sent_by set), grup/teknisi (WorkOrderDispatched),
        // OTP referrer, dan uji — SEMUA tidak boleh menaikkan counter pemutus.
        $this->gate()->recordOutcome($make(WhatsappEventType::InvoiceIssued, $user->id, false), false);
        $this->gate()->recordOutcome($make(WhatsappEventType::WorkOrderDispatched, null, false), false);
        $this->gate()->recordOutcome($make(WhatsappEventType::ReferrerActionOtp, null, false), false);
        $this->gate()->recordOutcome($make(WhatsappEventType::InvoiceIssued, null, true), false);

        $this->assertSame(0, CustomerAutoSendBreaker::count());

        // Kontrol: kegagalan OTOMATIS pelanggan (invoice_issued, sent_by null,
        // non-uji) MEMANG menaikkan counter.
        $this->gate()->recordOutcome($make(WhatsappEventType::InvoiceIssued, null, false), false);
        $this->assertSame(1, CustomerAutoSendBreaker::where('tenant_id', $tenant->id)->first()->consecutive_failures);
    }

    public function test_breaker_open_blocks_send(): void
    {
        $tenant = $this->tenantReady();
        CustomerAutoSendBreaker::create(['tenant_id' => $tenant->id, 'session_key' => 'direct', 'is_open' => true, 'consecutive_failures' => 10]);
        $customer = $this->customer($tenant);

        $decision = $this->gateway()->buildAndQueueGated(WhatsappEventType::CustomerSuspendedReminder, $customer);

        $this->assertSame(CustomerAutoSendResult::BreakerOpen, $decision->result);
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());
    }

    public function test_session_connected_transition_resumes_breaker(): void
    {
        $tenant = $this->tenantReady();
        CustomerAutoSendBreaker::create(['tenant_id' => $tenant->id, 'session_key' => 'direct', 'is_open' => true, 'consecutive_failures' => 10]);

        $this->gate()->onSessionConnected($tenant->id, null);

        $breaker = CustomerAutoSendBreaker::where('tenant_id', $tenant->id)->first();
        $this->assertFalse($breaker->is_open);
        $this->assertSame(0, $breaker->consecutive_failures);
    }

    public function test_artisan_resume_closes_breaker(): void
    {
        $tenant = $this->tenantReady();
        CustomerAutoSendBreaker::create(['tenant_id' => $tenant->id, 'session_key' => 'direct', 'is_open' => true, 'consecutive_failures' => 10]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->artisan(WhatsappAutoSendResume::class, ['tenant' => $tenant->id, '--by' => $user->id])->assertSuccessful();

        $breaker = CustomerAutoSendBreaker::where('tenant_id', $tenant->id)->first();
        $this->assertFalse($breaker->is_open);
        $this->assertSame($user->id, $breaker->resumed_by);
    }

    // ── Deferral customer_registered ─────────────────────────────────────

    public function test_registered_outside_window_defers_then_sends_when_window_opens(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 22, 0, 0, 'Asia/Jakarta')); // di luar jendela
        $tenant = $this->tenantReady();
        $customer = $this->customer($tenant);

        $decision = $this->gateway()->buildAndQueueGated(WhatsappEventType::CustomerRegistered, $customer);
        $this->assertSame(CustomerAutoSendResult::Deferred, $decision->result);
        $this->assertSame(1, CustomerAutoSendDeferral::where('customer_id', $customer->id)->where('status', 'pending')->count());
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());

        // Jendela buka → command memproses deferral.
        Carbon::setTestNow(Carbon::create(2026, 11, 22, 9, 0, 0, 'Asia/Jakarta'));
        $this->artisan(ProcessDeferredCustomerRegistered::class)->assertSuccessful();

        $this->assertSame('sent', CustomerAutoSendDeferral::where('customer_id', $customer->id)->first()->status);
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::CustomerRegistered->value)->count());
        Queue::assertPushed(SendWhatsappMessageJob::class);
    }

    public function test_one_active_deferral_per_customer(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 22, 0, 0, 'Asia/Jakarta'));
        $tenant = $this->tenantReady();
        $customer = $this->customer($tenant);

        $this->gateway()->buildAndQueueGated(WhatsappEventType::CustomerRegistered, $customer);
        $this->gateway()->buildAndQueueGated(WhatsappEventType::CustomerRegistered, $customer);

        $this->assertSame(1, CustomerAutoSendDeferral::where('customer_id', $customer->id)->where('status', 'pending')->count());
    }

    // ── Aturan tambahan 1: dedup simulasi 08:00 lalu 20:00 ───────────────

    public function test_due_reminder_run_twice_under_mode_uji_records_one_simulated_per_invoice(): void
    {
        $tenant = $this->tenantReady(['invoice_pdf_test_mode_enabled' => true]);
        $customer = $this->customer($tenant);
        $invoice = $this->pendingInvoice($tenant, $customer);

        // isScheduledNow() cocok: set jadwal = jam sekarang.
        WhatsappGatewaySettings::current()->update(['daily_schedule_times' => [now()->format('H:i')]]);
        $this->artisan(SendWhatsappDueReminders::class)->assertSuccessful();

        // "Run 20:00": jam lain yang masih dalam jendela + masih hari WIB yang sama.
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 19, 0, 0, 'Asia/Jakarta'));
        WhatsappGatewaySettings::current()->update(['daily_schedule_times' => [now()->format('H:i')]]);
        $this->artisan(SendWhatsappDueReminders::class)->assertSuccessful();

        $this->assertSame(1, CustomerAutoSendDecisionLog::where('invoice_id', $invoice->id)->where('result', 'simulated')->count());
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());
    }

    // ── Aturan tambahan 2: run-stop dicatat ≤1/hari ──────────────────────

    public function test_session_down_recorded_at_most_once_per_day_across_runs(): void
    {
        $tenant = $this->tenantReady();
        WhatsappSession::query()->where('tenant_id', $tenant->id)->update(['status' => WhatsappSessionStatus::LoggedOut]);
        $customer = $this->customer($tenant);
        $invoice = $this->pendingInvoice($tenant, $customer);

        for ($i = 0; $i < 4; $i++) {
            $this->gateway()->buildAndQueueGated(WhatsappEventType::InvoiceDueReminder, $customer, $invoice);
        }

        $this->assertLessThanOrEqual(1, CustomerAutoSendDecisionLog::where('result', 'session_down')->count());
    }

    // ── Command menghitung $sent hanya untuk Queued ──────────────────────

    public function test_due_reminder_command_counts_only_queued(): void
    {
        $tenant = $this->tenantReady();
        // c1 normal (akan Queued), c2 opt-out (SkippedOptOut).
        $c1 = $this->customer($tenant);
        $c2 = $this->customer($tenant, ['auto_notify_opt_out' => true]);
        $i1 = $this->pendingInvoice($tenant, $c1);
        $i2 = $this->pendingInvoice($tenant, $c2);

        WhatsappGatewaySettings::current()->update(['daily_schedule_times' => [now()->format('H:i')]]);
        $this->artisan(SendWhatsappDueReminders::class)
            ->expectsOutputToContain('1 pengingat due-date diantrikan')
            ->assertSuccessful();

        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::InvoiceDueReminder->value)->count());
    }
}
