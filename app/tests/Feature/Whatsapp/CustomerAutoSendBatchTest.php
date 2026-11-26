<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\CustomerAutoSendResult;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\WhatsappEventType;
use App\Enums\WhatsappMessageStatus;
use App\Enums\WhatsappSessionStatus;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Customer;
use App\Models\CustomerAutoSendBreaker;
use App\Models\CustomerAutoSendCursor;
use App\Models\CustomerAutoSendDecisionLog;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappGatewaySettings;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Billing\InvoicePdfService;
use App\Services\Whatsapp\CustomerAutoSendGate;
use App\Services\Whatsapp\CustomerAutoSendOrchestrator;
use App\Services\Whatsapp\WhatsappGatewayService;
use App\Support\WhatsappHmac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * v0.28.4 (SEBAR-BATCH, B-test) — jeda batch (->delay + kursor), recheck D4
 * (Cancelled), scope effective, pembersih A3, dan pagar command batch-test.
 */
class CustomerAutoSendBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 11, 25, 9, 0, 0, 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
            'customer_auto_send_window_start' => '08:00',
            'customer_auto_send_window_end' => '20:00',
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

    private function dueInvoice(Tenant $tenant, Customer $customer): Invoice
    {
        $sub = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);

        return Invoice::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'subscription_id' => $sub->id,
            'reseller_id' => null, 'status' => InvoiceStatus::Pending->value,
            'due_date' => now()->toDateString(), 'generated_at' => now()->subHours(2),
        ]);
    }

    private function setBatch(int $size, int $pauseMin, int $pauseMax): void
    {
        WhatsappGatewaySettings::current()->update([
            'rate_limit_batch_size' => $size,
            'rate_limit_batch_pause_min_minutes' => $pauseMin,
            'rate_limit_batch_pause_max_minutes' => $pauseMax,
            'rate_limit_delay_max_seconds' => 10,
        ]);
    }

    // ── JAMINAN RILIS: saklar baseline → nol efek ─────────────────────────

    public function test_release_guarantee_auto_notify_off_queues_nothing(): void
    {
        Queue::fake();
        $tenant = $this->tenantReady(['customer_auto_notify_enabled' => false]);
        $customer = $this->customer($tenant);

        $decision = $this->gateway()->buildAndQueueGated(WhatsappEventType::CustomerSuspendedReminder, $customer);

        $this->assertSame(CustomerAutoSendResult::SkippedAutoNotifyOff, $decision->result);
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());
        $this->assertSame(0, CustomerAutoSendDecisionLog::count());
        $this->assertSame(0, CustomerAutoSendCursor::count());
        Queue::assertNothingPushed();
    }

    // ── Jeda batch: batch 3 → #1-3 delay 0, #4-6 ≈ satu pause ─────────────

    public function test_batch_delay_first_batch_zero_then_one_pause(): void
    {
        Queue::fake();
        $this->setBatch(3, 5, 5); // pause tepat 5 menit = 300 detik
        $tenant = $this->tenantReady();

        $delays = [];
        for ($i = 0; $i < 6; $i++) {
            $customer = $this->customer($tenant);
            $invoice = $this->dueInvoice($tenant, $customer);
            $decision = $this->gateway()->buildAndQueueGated(WhatsappEventType::InvoiceDueReminder, $customer, $invoice);
            $this->assertSame(CustomerAutoSendResult::Queued, $decision->result);
            $delays[] = $decision->delaySeconds;
        }

        // Dalam batch: langkah intra = rate_limit_delay_max (10 dtk, A4) → slot
        // #1-3 = T0, T0+10, T0+20 (delay 0,10,20 — semua "≈0" vs satu pause).
        // Batas batch pada #3 menambah pause 5 mnt (300) ke slot terakhir
        // (T0+20) → #4 slot T0+320 (delay 320), lalu +10 intra: 330, 340.
        // Deterministik karena pause_min=max=5.
        $this->assertSame([0, 10, 20, 320, 330, 340], $delays);
        // Delay benar-benar tersematkan ke job (->delay()).
        Queue::assertPushed(SendWhatsappMessageJob::class, 6);
    }

    // ── Kursor BERTAHAN lintas tick (D2): tidak reset ke 0 ───────────────

    public function test_cursor_persists_across_ticks_not_reset(): void
    {
        Queue::fake();
        $this->setBatch(3, 5, 5);
        $tenant = $this->tenantReady();

        $queue = function () use ($tenant) {
            $customer = $this->customer($tenant);
            $invoice = $this->dueInvoice($tenant, $customer);

            return $this->gateway()->buildAndQueueGated(WhatsappEventType::InvoiceDueReminder, $customer, $invoice)->delaySeconds;
        };

        // Tick 1: dua pesan (delay 0, 10 — langkah intra). batch_count = 2.
        $this->assertSame(0, $queue());
        $this->assertSame(10, $queue());
        $cursor = CustomerAutoSendCursor::where('tenant_id', $tenant->id)->where('session_key', 'direct')->first();
        $this->assertNotNull($cursor);
        $this->assertSame(2, $cursor->batch_count);

        // Tick 2 (LANJUT, bukan reset): pesan ke-3 melengkapi batch (delay 20),
        // pesan ke-4 kena satu pause (320). Kalau kursor reset tiap tick, ke-3
        // akan 0 dan ke-4 akan 10 — bukan 20 & 320.
        $this->assertSame(20, $queue());
        $this->assertSame(320, $queue());
    }

    // ── Slot melewati jendela (D3): tidak di-queue ───────────────────────

    public function test_slot_past_window_end_is_not_queued(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::create(2026, 11, 25, 19, 59, 0, 'Asia/Jakarta'));
        $this->setBatch(1, 30, 30); // batch 1 → tiap pesan dorong next_slot +30 mnt
        $tenant = $this->tenantReady();

        $c1 = $this->customer($tenant);
        $first = $this->gateway()->buildAndQueueGated(WhatsappEventType::InvoiceDueReminder, $c1, $this->dueInvoice($tenant, $c1));
        $this->assertSame(CustomerAutoSendResult::Queued, $first->result);
        $this->assertSame(0, $first->delaySeconds);

        // Pesan ke-2: slot = 19:59 + 30 mnt = 20:29 > akhir jendela 20:00 → OutsideWindow.
        $c2 = $this->customer($tenant);
        $second = $this->gateway()->buildAndQueueGated(WhatsappEventType::InvoiceDueReminder, $c2, $this->dueInvoice($tenant, $c2));
        $this->assertSame(CustomerAutoSendResult::OutsideWindow, $second->result);
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->count());
    }

    // ── Recheck D4: tiap alasan → Cancelled, tak kirim, pemutus tak naik ──

    /**
     * @dataProvider recheckReasons
     */
    public function test_recheck_cancels_for_each_reason(string $flip, string $expectedReason): void
    {
        Http::fake();
        Queue::fake();
        $tenant = $this->tenantReady();
        $customer = $this->customer($tenant);
        $invoice = $this->dueInvoice($tenant, $customer);

        $log = WhatsappMessageLog::create([
            'tenant_id' => $tenant->id, 'reseller_id' => null, 'customer_id' => $customer->id,
            'invoice_id' => $invoice->id, 'phone_number' => '6281234567890',
            'event_type' => WhatsappEventType::InvoiceDueReminder, 'sent_by_user_id' => null,
            'rendered_content' => 'x', 'is_test' => false,
            'status' => WhatsappMessageStatus::Queued, 'queued_at' => now(),
        ]);

        // Ubah satu syarat jadi gagal.
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        match ($flip) {
            'auto_notify_off' => $settings->update(['customer_auto_notify_enabled' => false]),
            'mode_uji' => $settings->update(['invoice_pdf_test_mode_enabled' => true]),
            'opt_out' => $customer->update(['auto_notify_opt_out' => true]),
            'template_inactive' => WhatsappMessageTemplate::where('tenant_id', $tenant->id)
                ->where('event_type', WhatsappEventType::InvoiceDueReminder->value)->update(['is_active' => false]),
            'breaker' => CustomerAutoSendBreaker::create(['tenant_id' => $tenant->id, 'session_key' => 'direct', 'is_open' => true, 'consecutive_failures' => 10]),
            'session_down' => WhatsappSession::where('tenant_id', $tenant->id)->update(['status' => WhatsappSessionStatus::LoggedOut]),
            'outside_window' => Carbon::setTestNow(Carbon::create(2026, 11, 25, 21, 0, 0, 'Asia/Jakarta')),
        };

        (new SendWhatsappMessageJob($log->id))->handle(app(WhatsappHmac::class));

        $log->refresh();
        $this->assertSame(WhatsappMessageStatus::Cancelled, $log->status);
        $this->assertStringContainsString($expectedReason, (string) $log->failed_reason);
        Http::assertNothingSent();
        Queue::assertNothingPushed();

        // Pemutus TIDAK naik gara-gara batal (batal ≠ gagal kirim).
        $breaker = CustomerAutoSendBreaker::where('tenant_id', $tenant->id)->first();
        if ($flip !== 'breaker') {
            $this->assertTrue($breaker === null || $breaker->consecutive_failures === 0);
        }
    }

    public static function recheckReasons(): array
    {
        return [
            'auto_notify OFF' => ['auto_notify_off', 'skipped_auto_notify_off'],
            'Mode Uji ON' => ['mode_uji', 'skipped_test_mode'],
            'opt-out' => ['opt_out', 'skipped_opt_out'],
            'template nonaktif' => ['template_inactive', 'skipped_template_inactive'],
            'pemutus terbuka' => ['breaker', 'breaker_open'],
            'sesi mati' => ['session_down', 'session_down'],
            'luar jendela' => ['outside_window', 'outside_window'],
        ];
    }

    // ── Log Cancelled: tak dihitung plafon & re-collectable ──────────────

    public function test_cancelled_log_excluded_from_cap_and_dedup(): void
    {
        $tenant = $this->tenantReady();
        $customer = $this->customer($tenant);
        $invoice = $this->dueInvoice($tenant, $customer);

        $base = [
            'tenant_id' => $tenant->id, 'reseller_id' => null, 'customer_id' => $customer->id,
            'invoice_id' => $invoice->id, 'phone_number' => '6281234567890',
            'event_type' => WhatsappEventType::InvoiceIssued, 'sent_by_user_id' => null,
            'rendered_content' => 'x', 'is_test' => false, 'queued_at' => now(),
        ];
        WhatsappMessageLog::create($base + ['status' => WhatsappMessageStatus::Cancelled]);
        WhatsappMessageLog::create($base + ['status' => WhatsappMessageStatus::Sent]);

        // queuedToday (plafon) hanya menghitung yang effective (1 = yang Sent).
        $this->assertSame(1, app(CustomerAutoSendGate::class)->queuedToday($tenant->id));

        // scope effective: Cancelled dikecualikan.
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->effective()->where('invoice_id', $invoice->id)->count());
    }

    // ── Pembersih A3 ──────────────────────────────────────────────────────

    public function test_reaper_cancels_previous_day_and_post_window_but_spares_others(): void
    {
        // Sekarang 20:30 WIB → sudah lewat jendela 20:00 + 15 mnt.
        Carbon::setTestNow(Carbon::create(2026, 11, 25, 20, 30, 0, 'Asia/Jakarta'));
        $tenant = $this->tenantReady();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $customer = $this->customer($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $mk = fn (array $o) => WhatsappMessageLog::create(array_merge([
            'tenant_id' => $tenant->id, 'reseller_id' => null, 'customer_id' => $customer->id, 'invoice_id' => null,
            'phone_number' => '6281234567890', 'rendered_content' => 'x', 'is_test' => false,
            'status' => WhatsappMessageStatus::Queued, 'queued_at' => now(),
        ], $o));

        $prevDay = $mk(['event_type' => WhatsappEventType::InvoiceIssued, 'queued_at' => now()->subDay()]);
        $todayPost = $mk(['event_type' => WhatsappEventType::CustomerSuspendedReminder]);
        $manual = $mk(['event_type' => WhatsappEventType::InvoiceIssued, 'sent_by_user_id' => $user->id]);
        $otp = $mk(['event_type' => WhatsappEventType::ReferrerActionOtp]);
        $group = $mk(['event_type' => WhatsappEventType::WorkOrderCompleted]);
        $test = $mk(['event_type' => WhatsappEventType::InvoiceIssued, 'is_test' => true]);

        app(CustomerAutoSendOrchestrator::class)->cleanupStuckLogs($settings);

        $this->assertSame(WhatsappMessageStatus::Cancelled, $prevDay->refresh()->status);
        $this->assertSame(WhatsappMessageStatus::Cancelled, $todayPost->refresh()->status);
        $this->assertStringContainsString('kedaluwarsa', (string) $prevDay->failed_reason);
        // Manual / OTP / grup / uji TIDAK tersentuh.
        $this->assertSame(WhatsappMessageStatus::Queued, $manual->refresh()->status);
        $this->assertSame(WhatsappMessageStatus::Queued, $otp->refresh()->status);
        $this->assertSame(WhatsappMessageStatus::Queued, $group->refresh()->status);
        $this->assertSame(WhatsappMessageStatus::Queued, $test->refresh()->status);
    }

    public function test_reaper_within_window_spares_today_cancels_previous_day(): void
    {
        // 09:00 WIB (setUp) → masih dalam jendela: log hari ini yang queued masih sah.
        $tenant = $this->tenantReady();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $customer = $this->customer($tenant);

        $mk = fn (array $o) => WhatsappMessageLog::create(array_merge([
            'tenant_id' => $tenant->id, 'reseller_id' => null, 'customer_id' => $customer->id, 'invoice_id' => null,
            'phone_number' => '6281234567890', 'event_type' => WhatsappEventType::InvoiceIssued,
            'rendered_content' => 'x', 'is_test' => false,
            'status' => WhatsappMessageStatus::Queued, 'queued_at' => now(),
        ], $o));

        $today = $mk([]);
        $prevDay = $mk(['queued_at' => now()->subDay()]);

        app(CustomerAutoSendOrchestrator::class)->cleanupStuckLogs($settings);

        $this->assertSame(WhatsappMessageStatus::Queued, $today->refresh()->status);
        $this->assertSame(WhatsappMessageStatus::Cancelled, $prevDay->refresh()->status);
    }

    // ── Manual / recipient (OTP) tidak pernah di-delay batch ─────────────

    public function test_manual_path_is_never_batch_delayed(): void
    {
        Queue::fake();
        $this->setBatch(1, 30, 30); // kalau terkena batch, pesan berikutnya ber-delay besar
        $tenant = $this->tenantReady();
        WhatsappMessageTemplate::create([
            'tenant_id' => $tenant->id, 'reseller_id' => null,
            'event_type' => WhatsappEventType::ReferrerActionOtp->value,
            'content' => 'OTP {otp_code}', 'is_active' => true,
        ]);

        // Jalur recipient (OTP) — tak lewat gerbang, tak sentuh kursor.
        $this->gateway()->buildAndQueueForRecipient(
            WhatsappEventType::ReferrerActionOtp, $tenant->id, '6287770001111', ['otp_code' => '123456'],
        );

        // Tidak ada kursor dibuat oleh jalur non-otomatis.
        $this->assertSame(0, CustomerAutoSendCursor::count());
        Queue::assertPushed(SendWhatsappMessageJob::class, 1);
    }

    // ── Command batch-test: pagar ─────────────────────────────────────────

    public function test_batch_test_command_requires_confirm(): void
    {
        $this->artisan('whatsapp:auto-send-batch-test', ['--customer' => [1]])->assertFailed();
    }

    public function test_batch_test_command_rejects_non_test_customer(): void
    {
        Queue::fake();
        $tenant = $this->tenantReady();
        WorkOrderDispatchSettings::forTenant($tenant->id)->update(['invoice_pdf_test_recipient' => '6287884374939']);
        $real = $this->customer($tenant, ['name' => 'Pelanggan Nyata', 'phone_number' => '6287884374939']);

        $this->artisan('whatsapp:auto-send-batch-test', ['--customer' => [$real->id], '--confirm' => true])
            ->assertFailed();
        Queue::assertNothingPushed();
    }

    public function test_batch_test_command_rejects_wrong_number(): void
    {
        Queue::fake();
        $tenant = $this->tenantReady();
        WorkOrderDispatchSettings::forTenant($tenant->id)->update(['invoice_pdf_test_recipient' => '6287884374939']);
        $cust = $this->customer($tenant, ['name' => 'TEST Batch', 'phone_number' => '6289999999999']);

        $this->artisan('whatsapp:auto-send-batch-test', ['--customer' => [$cust->id], '--confirm' => true])
            ->assertFailed();
        Queue::assertNothingPushed();
    }

    public function test_batch_test_command_creates_distinct_periods_and_queues(): void
    {
        // v0.28.4 (cacat #1) — kelima invoice TEST harus berperiode BERBEDA
        // supaya unique (subscription_id, period_start, period_end) tak dilanggar;
        // dan OPSI (a) — command tak menunggu "sent", hanya meng-queue lalu cetak.
        Queue::fake();
        $tenant = $this->tenantReady(['invoice_pdf_test_recipient' => '6287884374939']);
        // Prasyarat: template due/suspend/registered NONAKTIF.
        WhatsappMessageTemplate::where('tenant_id', $tenant->id)
            ->whereIn('event_type', [
                WhatsappEventType::InvoiceDueReminder->value,
                WhatsappEventType::CustomerSuspendedReminder->value,
                WhatsappEventType::CustomerRegistered->value,
            ])->update(['is_active' => false]);
        $cust = $this->customer($tenant, ['name' => 'TEST T4', 'phone_number' => '6287884374939']);

        // Stub PDF: hindari render dompdf nyata di test — attachment tak dibaca
        // sampai job jalan (yang di-fake), jadi store() cukup no-op.
        $this->app->bind(InvoicePdfService::class, fn () => new class extends InvoicePdfService
        {
            public function __construct() {}

            public function store(Invoice $invoice): string
            {
                return 'invoice-pdfs/'.$invoice->tenant_id.'/'.$invoice->id.'.pdf';
            }
        });

        $this->artisan('whatsapp:auto-send-batch-test', [
            '--customer' => [$cust->id],
            '--confirm' => true,
            '--count' => 5,
            '--batch-size' => 3,
            '--pause-min' => 1,
            '--pause-max' => 2,
        ])->assertSuccessful();

        // 5 invoice TEST dengan periode (start,end) BERBEDA — tak ada unique violation.
        $invoices = Invoice::withoutGlobalScopes()->where('customer_id', $cust->id)->get();
        $this->assertCount(5, $invoices);
        $periods = $invoices->map(fn ($inv) => $inv->period_start.'|'.$inv->period_end)->unique();
        $this->assertCount(5, $periods, 'Periode tiap invoice TEST harus unik (cacat #1).');

        // 5 log Queued, semua ke nomor uji, event invoice_issued.
        $logs = WhatsappMessageLog::withoutGlobalScopes()->where('customer_id', $cust->id)->get();
        $this->assertCount(5, $logs);
        $this->assertTrue($logs->every(fn ($l) => $l->status === WhatsappMessageStatus::Queued));
        $this->assertTrue($logs->every(fn ($l) => $l->phone_number === '6287884374939'));
        $this->assertTrue($logs->every(fn ($l) => $l->event_type === WhatsappEventType::InvoiceIssued));
        Queue::assertPushed(SendWhatsappMessageJob::class, 5);

        // Saklar dipulihkan oleh finally.
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id)->refresh();
        $this->assertTrue((bool) $settings->invoice_pdf_test_mode_enabled === false);
        $this->assertTrue((bool) $settings->customer_auto_notify_enabled === true);
    }

    public function test_batch_test_command_rejects_when_auto_send_enabled(): void
    {
        Queue::fake();
        $tenant = $this->tenantReady([
            'invoice_pdf_test_recipient' => '6287884374939',
            'invoice_pdf_auto_send_enabled' => true,
        ]);
        // template due/suspend/registered nonaktif (prasyarat lain) supaya yang
        // gagal benar2 auto_send, bukan template.
        WhatsappMessageTemplate::where('tenant_id', $tenant->id)
            ->whereIn('event_type', [
                WhatsappEventType::InvoiceDueReminder->value,
                WhatsappEventType::CustomerSuspendedReminder->value,
                WhatsappEventType::CustomerRegistered->value,
            ])->update(['is_active' => false]);
        $cust = $this->customer($tenant, ['name' => 'TEST Batch', 'phone_number' => '6287884374939']);

        $this->artisan('whatsapp:auto-send-batch-test', ['--customer' => [$cust->id], '--confirm' => true])
            ->assertFailed();
        Queue::assertNothingPushed();
    }
}
