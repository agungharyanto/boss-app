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
use App\Models\CustomerAutoSendDecisionLog;
use App\Models\CustomerAutoSendDeferral;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Billing\InvoicePdfService;
use App\Services\Whatsapp\CustomerAutoSendOrchestrator;
use App\Support\Whatsapp\DueReminderDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v0.28.3 (T3, B6) — CustomerAutoSendOrchestrator: jaminan rilis, prioritas
 * K13, anti-starvation A2, jadwal hari (A5), pre-skip opt-out (A6), siklus
 * hidup deferral registrasi (kirim/menunggu/expired 48 jam), Mode Uji, dedup
 * tick berulang. Nol kirim nyata (Queue::fake + InvoicePdfService palsu).
 */
class CustomerAutoSendOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        // Dalam jendela (09:00 WIB) kecuali test yang override.
        Carbon::setTestNow(Carbon::create(2026, 11, 23, 9, 0, 0, 'Asia/Jakarta'));

        // Hindari render dompdf nyata untuk kandidat invoice sweep.
        $this->app->bind(InvoicePdfService::class, fn () => new class extends InvoicePdfService
        {
            public function __construct() {}

            public function store(Invoice $invoice): string
            {
                $path = "invoice-pdfs/{$invoice->tenant_id}/{$invoice->id}.pdf";
                Storage::disk('local')->put($path, '%PDF-1.4 dummy');

                return $path;
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function tenantReady(array $overrides = []): Tenant
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenant->id)->update(array_merge([
            'customer_auto_notify_enabled' => true,
            'invoice_pdf_test_mode_enabled' => false,
            'invoice_pdf_auto_send_enabled' => true,
            'invoice_pdf_auto_send_enabled_at' => now()->subDay(),
            'customer_auto_send_daily_cap' => 80,
            'due_reminder_days' => [5, 0],
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
                'event_type' => $event->value, 'content' => 'uji '.$event->value.' {customer_name}', 'is_active' => true,
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

    private function pendingInvoice(Tenant $tenant, Customer $customer, string $dueDate, ?Carbon $generatedAt = null): Invoice
    {
        $sub = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);

        return Invoice::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'subscription_id' => $sub->id,
            'reseller_id' => null, 'status' => InvoiceStatus::Pending->value,
            'due_date' => $dueDate, 'generated_at' => $generatedAt ?? now()->subHours(2),
        ]);
    }

    private function pendingDeferral(Tenant $tenant, Customer $customer, ?Carbon $createdAt = null): CustomerAutoSendDeferral
    {
        $deferral = CustomerAutoSendDeferral::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
            'status' => CustomerAutoSendDeferral::STATUS_PENDING,
        ]);

        if ($createdAt !== null) {
            // created_at bukan fillable; tulis langsung via query builder.
            CustomerAutoSendDeferral::query()->where('id', $deferral->id)->update(['created_at' => $createdAt]);
            $deferral->refresh();
        }

        return $deferral;
    }

    private function tick(): void
    {
        $this->artisan('whatsapp:auto-send-dispatch')->assertSuccessful();
    }

    private function autoLogCount(int $tenantId): int
    {
        return WhatsappMessageLog::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('event_type', [
                WhatsappEventType::InvoiceIssued->value,
                WhatsappEventType::InvoiceDueReminder->value,
                WhatsappEventType::CustomerSuspendedReminder->value,
                WhatsappEventType::CustomerRegistered->value,
            ])
            ->count();
    }

    // ── JAMINAN RILIS ────────────────────────────────────────────────────

    public function test_release_guarantee_production_posture_sends_nothing(): void
    {
        // Posture produksi tenant 1: auto_notify OFF, auto_send OFF, Mode Uji ON.
        $tenant = $this->tenantReady([
            'customer_auto_notify_enabled' => false,
            'invoice_pdf_auto_send_enabled' => false,
            'invoice_pdf_test_mode_enabled' => true,
            'invoice_pdf_test_recipient' => '6287884374939',
        ]);
        $c = $this->customer($tenant);
        $this->pendingInvoice($tenant, $c, now()->toDateString());        // H-0 due
        $this->customer($tenant, ['status' => CustomerStatus::Suspend]);  // suspend
        $deferral = $this->pendingDeferral($tenant, $c);                  // registrasi

        $this->tick();

        $this->assertSame(0, $this->autoLogCount($tenant->id));
        Queue::assertNothingPushed();
        $this->assertSame(0, CustomerAutoSendDecisionLog::count());
        // Registrasi saat saklar OFF: "langsung selesai" → dibatalkan, bukan menggantung.
        $this->assertSame(CustomerAutoSendDeferral::STATUS_CANCELLED, $deferral->fresh()->status);
        // 0 deferral BARU selain registrasi: total tetap 1 (yang dibuat test).
        $this->assertSame(1, CustomerAutoSendDeferral::count());
    }

    // ── PRIORITAS K13 di bawah cap kecil ─────────────────────────────────

    public function test_priority_order_queues_h0_then_h5_then_suspend_under_cap_three(): void
    {
        $tenant = $this->tenantReady(['customer_auto_send_daily_cap' => 3]);

        $cH0 = $this->customer($tenant);
        $iH0 = $this->pendingInvoice($tenant, $cH0, now()->toDateString());
        $cH5 = $this->customer($tenant);
        $iH5 = $this->pendingInvoice($tenant, $cH5, now()->copy()->addDays(5)->toDateString());
        $cSusp = $this->customer($tenant, ['status' => CustomerStatus::Suspend]);
        // Sweep: due_date jauh (bukan H-0/H-5) supaya HANYA kandidat invoice_issued.
        $cSweep = $this->customer($tenant);
        $iSweep = $this->pendingInvoice($tenant, $cSweep, now()->copy()->addDays(10)->toDateString());
        $cReg = $this->customer($tenant);
        $deferral = $this->pendingDeferral($tenant, $cReg);

        $this->tick();

        // Ter-queue PERSIS: H-0, H-5, suspend (cap 3).
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $iH0->id)->where('event_type', WhatsappEventType::InvoiceDueReminder->value)->count());
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $iH5->id)->where('event_type', WhatsappEventType::InvoiceDueReminder->value)->count());
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('customer_id', $cSusp->id)->where('event_type', WhatsappEventType::CustomerSuspendedReminder->value)->count());
        // Sweep & registrasi TIDAK ter-queue (cap tercapai).
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $iSweep->id)->count());
        $this->assertSame(CustomerAutoSendDeferral::STATUS_PENDING, $deferral->fresh()->status);
        $this->assertSame(3, $this->autoLogCount($tenant->id));
        // CapReached dicatat PERSIS sekali.
        $this->assertSame(1, CustomerAutoSendDecisionLog::where('result', CustomerAutoSendResult::CapReached->value)->count());
    }

    // ── Anti-starvation (A2) ─────────────────────────────────────────────

    public function test_h0_sent_before_many_sweep_and_suspend_in_the_morning(): void
    {
        $tenant = $this->tenantReady(['customer_auto_send_daily_cap' => 1]);

        // Banyak sweep (due_date jauh) + suspend, dibuat DULU (id lebih kecil).
        for ($i = 0; $i < 5; $i++) {
            $c = $this->customer($tenant);
            $this->pendingInvoice($tenant, $c, now()->copy()->addDays(10)->toDateString(), now()->subHours(6));
            $this->customer($tenant, ['status' => CustomerStatus::Suspend]);
        }
        // Satu H-0, dibuat TERAKHIR (id terbesar) — toh harus menang prioritas.
        $cH0 = $this->customer($tenant);
        $iH0 = $this->pendingInvoice($tenant, $cH0, now()->toDateString());

        $this->tick();

        // Cap 1 → satu-satunya yang ter-queue adalah H-0.
        $this->assertSame(1, $this->autoLogCount($tenant->id));
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $iH0->id)->where('event_type', WhatsappEventType::InvoiceDueReminder->value)->count());
    }

    // ── Jadwal hari (A5) ─────────────────────────────────────────────────

    public function test_reminder_days_single_day_three_only_matches_h_minus_three(): void
    {
        $tenant = $this->tenantReady(['due_reminder_days' => [3]]);
        $cNow = $this->customer($tenant);
        $iH0 = $this->pendingInvoice($tenant, $cNow, now()->toDateString());
        $cH3 = $this->customer($tenant);
        $iH3 = $this->pendingInvoice($tenant, $cH3, now()->copy()->addDays(3)->toDateString());
        $cH5 = $this->customer($tenant);
        $iH5 = $this->pendingInvoice($tenant, $cH5, now()->copy()->addDays(5)->toDateString());

        // Matikan sweep supaya hanya jalur due yang mengirim (iH0/iH3/iH5 juga
        // sweep-eligible kalau tidak).
        WorkOrderDispatchSettings::forTenant($tenant->id)->update(['invoice_pdf_auto_send_enabled' => false]);

        $this->tick();

        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $iH3->id)->where('event_type', WhatsappEventType::InvoiceDueReminder->value)->count());
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $iH0->id)->count());
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $iH5->id)->count());
    }

    public function test_reminder_days_default_five_zero_matches_h5_and_h0(): void
    {
        $tenant = $this->tenantReady(['due_reminder_days' => [5, 0], 'invoice_pdf_auto_send_enabled' => false]);
        $cH0 = $this->customer($tenant);
        $iH0 = $this->pendingInvoice($tenant, $cH0, now()->toDateString());
        $cH5 = $this->customer($tenant);
        $iH5 = $this->pendingInvoice($tenant, $cH5, now()->copy()->addDays(5)->toDateString());
        $cH3 = $this->customer($tenant);
        $iH3 = $this->pendingInvoice($tenant, $cH3, now()->copy()->addDays(3)->toDateString());

        $this->tick();

        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $iH0->id)->count());
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $iH5->id)->count());
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $iH3->id)->count());
    }

    public function test_due_reminder_days_validation_rejects_invalid_and_accepts_valid(): void
    {
        $this->assertFalse(DueReminderDays::isValid([8]));
        $this->assertFalse(DueReminderDays::isValid([-1]));
        $this->assertFalse(DueReminderDays::isValid([]));
        $this->assertFalse(DueReminderDays::isValid('bukan-array'));
        $this->assertTrue(DueReminderDays::isValid([5, 0]));
        $this->assertTrue(DueReminderDays::isValid([3]));
        $this->assertTrue(DueReminderDays::isValid([0, 1, 2, 3, 4, 5, 6, 7]));

        // sanitize: nilai rusak/kosong → DEFAULT [5,0]; valid → ter-dedup.
        $this->assertSame([5, 0], DueReminderDays::sanitize([]));
        $this->assertSame([5, 0], DueReminderDays::sanitize([8, -1]));
        $this->assertSame([5, 0], DueReminderDays::sanitize(null));
        $this->assertSame([3], DueReminderDays::sanitize([3, 3]));
    }

    // ── Pre-skip opt-out (A6) ────────────────────────────────────────────

    public function test_pre_skip_opt_out_records_no_skipped_optout_across_two_runs(): void
    {
        $tenant = $this->tenantReady(['invoice_pdf_auto_send_enabled' => false]);
        $cOpt = $this->customer($tenant, ['auto_notify_opt_out' => true]);
        $this->pendingInvoice($tenant, $cOpt, now()->toDateString());            // due opt-out
        $this->customer($tenant, ['status' => CustomerStatus::Suspend, 'auto_notify_opt_out' => true]); // suspend opt-out

        $this->tick();
        $this->tick();

        $this->assertSame(0, CustomerAutoSendDecisionLog::where('result', CustomerAutoSendResult::SkippedOptOut->value)->count());
        $this->assertSame(0, $this->autoLogCount($tenant->id));
    }

    // ── Registrasi lewat deferral ────────────────────────────────────────

    public function test_registration_sent_on_next_tick_in_window(): void
    {
        $tenant = $this->tenantReady(['invoice_pdf_auto_send_enabled' => false]);
        $c = $this->customer($tenant);
        $deferral = $this->pendingDeferral($tenant, $c);

        $this->tick();

        $this->assertSame(CustomerAutoSendDeferral::STATUS_SENT, $deferral->fresh()->status);
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::CustomerRegistered->value)->count());
        Queue::assertPushed(SendWhatsappMessageJob::class);
    }

    public function test_registration_waits_outside_window(): void
    {
        $tenant = $this->tenantReady(['invoice_pdf_auto_send_enabled' => false]);
        $c = $this->customer($tenant);
        $deferral = $this->pendingDeferral($tenant, $c);

        Carbon::setTestNow(Carbon::create(2026, 11, 23, 21, 0, 0, 'Asia/Jakarta')); // di luar jendela
        $this->tick();

        $this->assertSame(CustomerAutoSendDeferral::STATUS_PENDING, $deferral->fresh()->status);
        $this->assertSame(0, $this->autoLogCount($tenant->id));
    }

    public function test_registration_expires_after_48_hours(): void
    {
        $tenant = $this->tenantReady(['invoice_pdf_auto_send_enabled' => false]);
        $c = $this->customer($tenant);
        $deferral = $this->pendingDeferral($tenant, $c, now()->copy()->subHours(49));

        $this->tick();

        $this->assertSame(CustomerAutoSendDeferral::STATUS_EXPIRED, $deferral->fresh()->status);
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::CustomerRegistered->value)->count());
        // Tepat SATU baris keputusan Expired.
        $this->assertSame(1, CustomerAutoSendDecisionLog::where('result', CustomerAutoSendResult::Expired->value)->count());
    }

    // ── Mode Uji ─────────────────────────────────────────────────────────

    public function test_mode_uji_simulates_reminders_and_skips_sweep_no_log(): void
    {
        $tenant = $this->tenantReady(['invoice_pdf_test_mode_enabled' => true, 'invoice_pdf_test_recipient' => '6287884374939']);
        $cDue = $this->customer($tenant);
        $iDue = $this->pendingInvoice($tenant, $cDue, now()->toDateString());        // H-0 due
        $cSusp = $this->customer($tenant, ['status' => CustomerStatus::Suspend]);
        // Sweep invoice (due_date jauh).
        $cSweep = $this->customer($tenant);
        $this->pendingInvoice($tenant, $cSweep, now()->copy()->addDays(10)->toDateString());
        $cReg = $this->customer($tenant);
        $deferral = $this->pendingDeferral($tenant, $cReg);

        $this->tick();

        // due/suspend/registrasi → Simulated (3 baris); invoice → SkippedTestMode.
        $this->assertSame(3, CustomerAutoSendDecisionLog::where('result', CustomerAutoSendResult::Simulated->value)->count());
        $this->assertGreaterThanOrEqual(1, CustomerAutoSendDecisionLog::where('result', CustomerAutoSendResult::SkippedTestMode->value)->count());
        $this->assertSame(0, $this->autoLogCount($tenant->id));
        Queue::assertNothingPushed();
        $this->assertSame(CustomerAutoSendDeferral::STATUS_SIMULATED, $deferral->fresh()->status);
    }

    // ── Dedup tick berulang satu hari ────────────────────────────────────

    public function test_repeated_tick_same_day_does_not_duplicate(): void
    {
        $tenant = $this->tenantReady();
        $cDue = $this->customer($tenant);
        $iDue = $this->pendingInvoice($tenant, $cDue, now()->toDateString());
        $cSusp = $this->customer($tenant, ['status' => CustomerStatus::Suspend]);

        $this->tick();
        // Tick kedua jam lain, masih dalam jendela + hari WIB sama.
        Carbon::setTestNow(Carbon::create(2026, 11, 23, 19, 0, 0, 'Asia/Jakarta'));
        $this->tick();

        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $iDue->id)->where('event_type', WhatsappEventType::InvoiceDueReminder->value)->count());
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('customer_id', $cSusp->id)->where('event_type', WhatsappEventType::CustomerSuspendedReminder->value)->count());
    }

    // ── Anti-banjir baris keputusan (pra-rilis item 1): 30 tick berturut ─────

    /**
     * (a) Template suspend NONAKTIF + Mode Uji OFF: kandidat suspend dikumpulkan
     * ulang tiap tick (tak ada log/Simulated penanda "sudah ditangani"), tapi
     * SkippedTemplateInactive dicatat MAKSIMAL 1 baris per kandidat per hari WIB.
     */
    public function test_thirty_ticks_with_inactive_suspend_template_record_one_skipped_template_inactive(): void
    {
        $tenant = $this->tenantReady(['invoice_pdf_auto_send_enabled' => false]);
        WhatsappMessageTemplate::where('tenant_id', $tenant->id)
            ->where('event_type', WhatsappEventType::CustomerSuspendedReminder->value)
            ->update(['is_active' => false]);
        $cSusp = $this->customer($tenant, ['status' => CustomerStatus::Suspend]);

        $base = Carbon::create(2026, 11, 23, 9, 0, 0, 'Asia/Jakarta');
        for ($i = 0; $i < 30; $i++) {
            Carbon::setTestNow($base->copy()->addMinutes($i)); // everyMinute, tetap dalam jendela + hari WIB sama
            $this->tick();
        }

        $this->assertSame(
            1,
            CustomerAutoSendDecisionLog::where('path', WhatsappEventType::CustomerSuspendedReminder->value)
                ->where('result', CustomerAutoSendResult::SkippedTemplateInactive->value)
                ->where('customer_id', $cSusp->id)
                ->count(),
        );
        $this->assertSame(1, CustomerAutoSendDecisionLog::where('result', CustomerAutoSendResult::SkippedTemplateInactive->value)->count());
        $this->assertSame(0, $this->autoLogCount($tenant->id));
        Queue::assertNothingPushed();
    }

    /**
     * (b) auto_send ON + Mode Uji ON: due/suspend/registrasi → Simulated (3
     * baris, satu per kandidat), invoice sweep → SkippedTestMode (1 baris per
     * jalur/hari). 30 tick tidak menambah baris apa pun.
     */
    public function test_thirty_ticks_under_mode_uji_record_at_most_one_row_per_candidate(): void
    {
        $tenant = $this->tenantReady(['invoice_pdf_test_mode_enabled' => true, 'invoice_pdf_test_recipient' => '6287884374939']);
        $cDue = $this->customer($tenant);
        $this->pendingInvoice($tenant, $cDue, now()->toDateString());                 // H-0 due
        $this->customer($tenant, ['status' => CustomerStatus::Suspend]);              // suspend
        $cSweep = $this->customer($tenant);
        $this->pendingInvoice($tenant, $cSweep, now()->copy()->addDays(10)->toDateString()); // sweep (due_date jauh)
        $cReg = $this->customer($tenant);
        $this->pendingDeferral($tenant, $cReg);                                       // registrasi

        $base = Carbon::create(2026, 11, 23, 9, 0, 0, 'Asia/Jakarta');
        for ($i = 0; $i < 30; $i++) {
            Carbon::setTestNow($base->copy()->addMinutes($i));
            $this->tick();
        }

        // due + suspend + registrasi = 3 Simulated (satu per kandidat).
        $this->assertSame(3, CustomerAutoSendDecisionLog::where('result', CustomerAutoSendResult::Simulated->value)->count());
        // invoice sweep = 1 SkippedTestMode (per jalur/hari).
        $this->assertSame(1, CustomerAutoSendDecisionLog::where('result', CustomerAutoSendResult::SkippedTestMode->value)->count());
        // Total hanya 4 baris walau 30 tick — tidak ada banjir.
        $this->assertSame(4, CustomerAutoSendDecisionLog::count());
        $this->assertSame(0, $this->autoLogCount($tenant->id));
        Queue::assertNothingPushed();
    }

    // ── Kolektor invoice: saklar terpisah + semantik sweep lama (item 3) ─────

    /**
     * (3a) Kolektor invoice (sweep) di-gate `invoice_pdf_auto_send_enabled`,
     * TERPISAH dari `customer_auto_notify_enabled`. Dengan auto_notify ON + Mode
     * Uji OFF + invoice sweep-eligible: saklar OFF → NOL kirim; saklar ON → kirim.
     * Membuktikan saklar invoice-lah yang menggerbang, bukan auto_notify.
     * (Orkestrator CustomerAutoSendOrchestrator.php: gate di dispatchTenant,
     * baris `$autoNotify && $settings->invoice_pdf_auto_send_enabled`.)
     */
    public function test_invoice_sweep_gated_by_its_own_switch_separate_from_auto_notify(): void
    {
        // auto_notify ON, Mode Uji OFF, sweep OFF. due_reminder_days default [5,0].
        $tenant = $this->tenantReady([
            'invoice_pdf_auto_send_enabled' => false,
            'invoice_pdf_auto_send_enabled_at' => now()->subDay(),
        ]);
        $c = $this->customer($tenant);
        // due_date jauh (10 hari) → HANYA kandidat sweep, bukan due reminder.
        $invoice = $this->pendingInvoice($tenant, $c, now()->copy()->addDays(10)->toDateString(), now()->subHours(2));

        $this->tick();

        // Saklar sweep OFF → kolektor tidak jalan → nol kirim, nol baris keputusan.
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(0, CustomerAutoSendDecisionLog::where('path', WhatsappEventType::InvoiceIssued->value)->count());
        $this->assertSame(0, $this->autoLogCount($tenant->id));

        // Nyalakan HANYA saklar sweep (auto_notify tetap ON sejak awal) → kirim.
        WorkOrderDispatchSettings::forTenant($tenant->id)->update(['invoice_pdf_auto_send_enabled' => true]);
        $this->tick();

        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()
            ->where('invoice_id', $invoice->id)
            ->where('event_type', WhatsappEventType::InvoiceIssued->value)
            ->count());
    }

    /**
     * (3b) Semantik lama sweep dipertahankan: batas bawah = enabled_at (atau
     * `invoice_pdf_auto_send_from`), `generated_at >= batas bawah`. Invoice
     * backlog (terbit SEBELUM saklar menyala) TIDAK ikut tersapu.
     */
    public function test_invoice_sweep_lower_bound_excludes_backlog_before_enabled_at(): void
    {
        $enabledAt = Carbon::create(2026, 11, 23, 6, 0, 0, 'Asia/Jakarta');
        $tenant = $this->tenantReady([
            'invoice_pdf_auto_send_enabled' => true,
            'invoice_pdf_auto_send_enabled_at' => $enabledAt,
            'invoice_pdf_auto_send_from' => null,
        ]);

        $cBacklog = $this->customer($tenant);
        $iBacklog = $this->pendingInvoice($tenant, $cBacklog, now()->copy()->addDays(10)->toDateString(), $enabledAt->copy()->subHour());  // sebelum batas bawah
        $cNew = $this->customer($tenant);
        $iNew = $this->pendingInvoice($tenant, $cNew, now()->copy()->addDays(10)->toDateString(), $enabledAt->copy()->addHour());          // setelah batas bawah

        $this->tick();

        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $iBacklog->id)->count());
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $iNew->id)->where('event_type', WhatsappEventType::InvoiceIssued->value)->count());
    }

    /**
     * (3c) Dedup sweep lama dipertahankan: SUDAH ada log invoice_issued NON-UJI
     * → dilewati; log UJI (is_test=true) TIDAK men-dedup → tetap tersapu.
     */
    public function test_invoice_sweep_dedup_counts_non_test_log_only(): void
    {
        $tenant = $this->tenantReady(['invoice_pdf_auto_send_enabled_at' => now()->subDay()]);

        $cAlready = $this->customer($tenant);
        $iAlready = $this->pendingInvoice($tenant, $cAlready, now()->copy()->addDays(10)->toDateString());
        $this->sentInvoiceLog($tenant->id, $iAlready, isTest: false); // log NON-UJI → dedup

        $cTestOnly = $this->customer($tenant);
        $iTestOnly = $this->pendingInvoice($tenant, $cTestOnly, now()->copy()->addDays(10)->toDateString());
        $this->sentInvoiceLog($tenant->id, $iTestOnly, isTest: true); // log UJI → TIDAK dedup

        $this->tick();

        // iAlready: tidak ada log NON-UJI baru (tetap 1, yang pre-seed).
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()
            ->where('invoice_id', $iAlready->id)->where('event_type', WhatsappEventType::InvoiceIssued->value)->where('is_test', false)->count());
        // iTestOnly: log uji lama tidak men-dedup → kini ada 1 log NON-UJI baru.
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()
            ->where('invoice_id', $iTestOnly->id)->where('event_type', WhatsappEventType::InvoiceIssued->value)->where('is_test', false)->count());
    }

    // ── Flag jeda orkestrator (A6) — TTL wajib ───────────────────────────

    /**
     * v0.28.4 pra-rilis (item 3) — flag jeda di-set DENGAN TTL
     * (PAUSE_TTL_MINUTES, 25 mnt > batas uji 20 mnt): saat ON orkestrator tak
     * mengumpulkan kandidat NYATA; begitu TTL lewat flag kedaluwarsa SENDIRI
     * (pengaman bila command uji mati paksa sebelum finally melepasnya) dan tick
     * berikutnya kembali mengantre. Array cache (driver test) menghormati
     * Carbon::setTestNow untuk kedaluwarsa (currentTime pakai Carbon::now).
     */
    public function test_pause_flag_with_ttl_halts_then_auto_expires_and_resumes(): void
    {
        $tenant = $this->tenantReady();
        $c = $this->customer($tenant);
        $invoice = $this->pendingInvoice($tenant, $c, now()->toDateString()); // H-0 due

        // Flag ON dengan TTL produksi (mekanisme IDENTIK command uji).
        Cache::put(
            CustomerAutoSendOrchestrator::PAUSE_CACHE_KEY,
            true,
            now()->addMinutes(CustomerAutoSendOrchestrator::PAUSE_TTL_MINUTES),
        );
        $this->assertTrue(app(CustomerAutoSendOrchestrator::class)->isPaused());

        // Tick saat jeda: NOL kandidat nyata terkumpul (hanya reaper A3 jalan).
        $this->tick();
        $this->assertSame(0, $this->autoLogCount($tenant->id));
        $this->assertSame(0, CustomerAutoSendDecisionLog::count());
        Queue::assertNothingPushed();

        // Lewati TTL (26 mnt > 25) — masih dalam jendela (09:26 WIB). Flag
        // kedaluwarsa SENDIRI tanpa Cache::forget.
        Carbon::setTestNow(now()->addMinutes(CustomerAutoSendOrchestrator::PAUSE_TTL_MINUTES + 1));
        $this->assertNull(Cache::get(CustomerAutoSendOrchestrator::PAUSE_CACHE_KEY));
        $this->assertFalse(app(CustomerAutoSendOrchestrator::class)->isPaused());

        // Tick berikutnya: orkestrator jalan lagi → H-0 ter-queue.
        $this->tick();
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()
            ->where('invoice_id', $invoice->id)
            ->where('event_type', WhatsappEventType::InvoiceDueReminder->value)
            ->count());
    }

    private function sentInvoiceLog(int $tenantId, Invoice $invoice, bool $isTest): WhatsappMessageLog
    {
        return WhatsappMessageLog::create([
            'tenant_id' => $tenantId, 'reseller_id' => null,
            'customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id,
            'phone_number' => '6281234567890', 'event_type' => WhatsappEventType::InvoiceIssued,
            'is_test' => $isTest, 'rendered_content' => 'x',
            'status' => WhatsappMessageStatus::Sent, 'queued_at' => now(),
        ]);
    }
}
