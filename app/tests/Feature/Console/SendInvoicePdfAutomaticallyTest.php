<?php

namespace Tests\Feature\Console;

use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\WhatsappEventType;
use App\Enums\WhatsappSessionStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Billing\InvoicePdfService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v0.13.5.3.1 — sweep kirim PDF invoice OTOMATIS. Nol kirim nyata (Queue::fake
 * + InvoicePdfService dipalsukan supaya tidak render dompdf). Jam diset
 * eksplisit di window kirim untuk setiap test non-window.
 */
class SendInvoicePdfAutomaticallyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');

        // Default: jam 09:00 WIB (di dalam window) untuk semua test kecuali
        // test window sendiri yang meng-override.
        Carbon::setTestNow(Carbon::create(2026, 11, 10, 9, 0, 0, 'Asia/Jakarta'));

        $this->app->bind(InvoicePdfService::class, fn () => new class extends InvoicePdfService
        {
            public function __construct() {}

            public function render(Invoice $invoice): string
            {
                return '%PDF-1.4 dummy';
            }

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

    private function tenantWithAutoSend(array $overrides = []): Tenant
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenant->id)->update(array_merge([
            'customer_auto_notify_enabled' => true,
            'invoice_pdf_test_mode_enabled' => false,
            'invoice_pdf_auto_send_enabled' => true,
            'invoice_pdf_auto_send_enabled_at' => now()->subDay(),
            'invoice_pdf_auto_send_from' => null,
            'invoice_pdf_auto_send_daily_cap' => 80,
        ], $overrides));

        $this->template($tenant->id);
        $this->connectedSession($tenant->id);

        return $tenant;
    }

    private function template(int $tenantId, bool $active = true): void
    {
        WhatsappMessageTemplate::create([
            'tenant_id' => $tenantId,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::InvoiceIssued->value,
            'content' => 'Halo {customer_name}, tagihan {invoice_number} total {total_amount}. {company_name}',
            'is_active' => $active,
        ]);
    }

    private function connectedSession(int $tenantId, ?int $resellerId = null, WhatsappSessionStatus $status = WhatsappSessionStatus::Connected): void
    {
        WhatsappSession::factory()->create([
            'tenant_id' => $tenantId,
            'reseller_id' => $resellerId,
            'status' => $status,
        ]);
    }

    private function eligibleInvoice(
        int $tenantId,
        ?Carbon $generatedAt = null,
        InvoiceStatus $status = InvoiceStatus::Pending,
        string $phone = '081234567890',
        CustomerStatus $customerStatus = CustomerStatus::Aktif,
        ?int $resellerId = null,
    ): Invoice {
        $customer = Customer::factory()->create([
            'tenant_id' => $tenantId,
            'reseller_id' => $resellerId,
            'phone_number' => $phone,
            'status' => $customerStatus,
        ]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'reseller_id' => $resellerId,
        ]);

        return Invoice::factory()->create([
            'tenant_id' => $tenantId,
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'reseller_id' => $resellerId,
            'status' => $status,
            'generated_at' => $generatedAt ?? now()->subHours(2),
        ]);
    }

    private function runSweep(bool $dryRun = false): void
    {
        $this->artisan('invoices:send-pdf-automatically'.($dryRun ? ' --dry-run' : ''))->assertSuccessful();
    }

    private function queuedCount(int $tenantId): int
    {
        return WhatsappMessageLog::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('event_type', WhatsappEventType::InvoiceIssued->value)
            ->where('is_test', false)
            ->count();
    }

    // ── Gate saklar ganda + Mode Uji ─────────────────────────────────────

    public function test_happy_path_queues_one_eligible_invoice(): void
    {
        $tenant = $this->tenantWithAutoSend();
        $invoice = $this->eligibleInvoice($tenant->id);

        $this->runSweep();

        $this->assertSame(1, $this->queuedCount($tenant->id));
        $log = WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($log);
        $this->assertFalse($log->is_test);
        $this->assertNull($log->sent_by_user_id); // jalur otomatis
    }

    public function test_auto_send_off_queues_nothing(): void
    {
        $tenant = $this->tenantWithAutoSend(['invoice_pdf_auto_send_enabled' => false]);
        $this->eligibleInvoice($tenant->id);

        $this->runSweep();

        $this->assertSame(0, $this->queuedCount($tenant->id));
    }

    public function test_customer_auto_notify_off_queues_nothing(): void
    {
        $tenant = $this->tenantWithAutoSend(['customer_auto_notify_enabled' => false]);
        $this->eligibleInvoice($tenant->id);

        $this->runSweep();

        $this->assertSame(0, $this->queuedCount($tenant->id));
    }

    public function test_test_mode_on_queues_nothing(): void
    {
        $tenant = $this->tenantWithAutoSend([
            'invoice_pdf_test_mode_enabled' => true,
            'invoice_pdf_test_recipient' => '6287884374939',
        ]);
        $this->eligibleInvoice($tenant->id);

        $this->runSweep();

        $this->assertSame(0, $this->queuedCount($tenant->id));
    }

    // ── Jendela jam ──────────────────────────────────────────────────────

    public function test_window_hours(): void
    {
        $cases = [
            ['07:59', false],
            ['08:00', true],
            ['19:59', true],
            ['20:00', false],
        ];

        foreach ($cases as [$time, $shouldSend]) {
            [$h, $m] = explode(':', $time);
            Carbon::setTestNow(Carbon::create(2026, 11, 10, (int) $h, (int) $m, 0, 'Asia/Jakarta'));

            $tenant = $this->tenantWithAutoSend();
            $this->eligibleInvoice($tenant->id);

            $this->runSweep();

            $this->assertSame(
                $shouldSend ? 1 : 0,
                $this->queuedCount($tenant->id),
                "Jam {$time} seharusnya ".($shouldSend ? 'mengirim' : 'tidak mengirim')
            );
        }
    }

    // ── Batas bawah generated_at ─────────────────────────────────────────

    public function test_invoice_generated_before_enabled_at_is_excluded(): void
    {
        $tenant = $this->tenantWithAutoSend(['invoice_pdf_auto_send_enabled_at' => now()->subDay()]);
        // Terbit 2 hari lalu (sebelum enabled_at kemarin) → dikecualikan.
        $this->eligibleInvoice($tenant->id, generatedAt: now()->subDays(2));

        $this->runSweep();

        $this->assertSame(0, $this->queuedCount($tenant->id));
    }

    public function test_from_date_overrides_lower_bound(): void
    {
        $tenant = $this->tenantWithAutoSend([
            'invoice_pdf_auto_send_enabled_at' => now()->subDays(10),
            'invoice_pdf_auto_send_from' => now()->subDays(3)->toDateString(),
        ]);
        // Terbit 5 hari lalu: setelah enabled_at(-10) TAPI sebelum from(-3) →
        // dikecualikan (from menang).
        $this->eligibleInvoice($tenant->id, generatedAt: now()->subDays(5));
        // Terbit kemarin: setelah from → dikirim.
        $this->eligibleInvoice($tenant->id, generatedAt: now()->subDay());

        $this->runSweep();

        $this->assertSame(1, $this->queuedCount($tenant->id));
    }

    // ── Status invoice ───────────────────────────────────────────────────

    public function test_only_pending_is_sent(): void
    {
        $tenant = $this->tenantWithAutoSend();
        $this->eligibleInvoice($tenant->id, status: InvoiceStatus::Paid);
        $this->eligibleInvoice($tenant->id, status: InvoiceStatus::Overdue);
        $this->eligibleInvoice($tenant->id, status: InvoiceStatus::Cancelled);
        $this->eligibleInvoice($tenant->id, status: InvoiceStatus::Draft);
        $pending = $this->eligibleInvoice($tenant->id, status: InvoiceStatus::Pending);

        $this->runSweep();

        $this->assertSame(1, $this->queuedCount($tenant->id));
        $this->assertDatabaseHas('whatsapp_message_logs', [
            'invoice_id' => $pending->id,
            'event_type' => WhatsappEventType::InvoiceIssued->value,
        ]);
    }

    // ── Cap harian ───────────────────────────────────────────────────────

    public function test_daily_cap_limits_queue_and_leaves_rest(): void
    {
        $tenant = $this->tenantWithAutoSend(['invoice_pdf_auto_send_daily_cap' => 3]);
        for ($i = 0; $i < 5; $i++) {
            $this->eligibleInvoice($tenant->id, generatedAt: now()->subHours(5 - $i));
        }

        $this->runSweep();
        $this->assertSame(3, $this->queuedCount($tenant->id)); // cap

        // "Hari berikutnya" (baseline hari ini belum reset di test) → run lagi
        // tetap 3 karena 3 sudah terkirim hari ini + cap 3; buktikan tidak
        // melampaui cap, bukan reset.
        $this->runSweep();
        $this->assertSame(3, $this->queuedCount($tenant->id));
    }

    public function test_test_logs_do_not_count_toward_cap(): void
    {
        $tenant = $this->tenantWithAutoSend(['invoice_pdf_auto_send_daily_cap' => 1]);
        // Satu log UJI hari ini (sesi direct) — TIDAK mengurangi kuota.
        WhatsappMessageLog::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'invoice_id' => null,
            'phone_number' => '6287884374939',
            'event_type' => WhatsappEventType::InvoiceIssued->value,
            'rendered_content' => 'x',
            'status' => 'sent',
            'queued_at' => now(),
            'is_test' => true,
        ]);
        $this->eligibleInvoice($tenant->id);

        $this->runSweep();

        $this->assertSame(1, $this->queuedCount($tenant->id)); // tetap terkirim
    }

    // ── Dedup / idempotensi ──────────────────────────────────────────────

    public function test_invoice_with_existing_nontest_log_is_skipped(): void
    {
        $tenant = $this->tenantWithAutoSend();
        $invoice = $this->eligibleInvoice($tenant->id);
        // Sudah ada log non-uji (mis. dari kirim manual) → dilewati.
        WhatsappMessageLog::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'invoice_id' => $invoice->id,
            'phone_number' => '081234567890',
            'event_type' => WhatsappEventType::InvoiceIssued->value,
            'rendered_content' => 'x',
            'status' => 'sent',
            'queued_at' => now(),
            'is_test' => false,
            'sent_by_user_id' => null,
        ]);

        $this->runSweep();

        $this->assertSame(1, $this->queuedCount($tenant->id)); // tetap 1, tak nambah
    }

    public function test_two_runs_produce_one_message_per_invoice(): void
    {
        $tenant = $this->tenantWithAutoSend();
        $this->eligibleInvoice($tenant->id);

        $this->runSweep();
        $this->runSweep();

        $this->assertSame(1, $this->queuedCount($tenant->id));
    }

    public function test_unique_index_rejects_a_second_automatic_log(): void
    {
        $tenant = $this->tenantWithAutoSend();
        $invoice = $this->eligibleInvoice($tenant->id);

        $base = [
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'invoice_id' => $invoice->id,
            'phone_number' => '081234567890',
            'event_type' => WhatsappEventType::InvoiceIssued->value,
            'rendered_content' => 'x',
            'status' => 'sent',
            'queued_at' => now(),
            'is_test' => false,
            'sent_by_user_id' => null,
        ];
        WhatsappMessageLog::withoutGlobalScopes()->create($base);

        $this->expectException(QueryException::class);
        WhatsappMessageLog::withoutGlobalScopes()->create($base);
    }

    public function test_unique_index_allows_manual_resend_nontest_logs(): void
    {
        // sent_by_user_id terisi (manual) → index TIDAK memblokir dua log.
        $tenant = $this->tenantWithAutoSend();
        $invoice = $this->eligibleInvoice($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $base = [
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'invoice_id' => $invoice->id,
            'phone_number' => '081234567890',
            'event_type' => WhatsappEventType::InvoiceIssued->value,
            'rendered_content' => 'x',
            'status' => 'sent',
            'queued_at' => now(),
            'is_test' => false,
            'sent_by_user_id' => $user->id,
        ];
        WhatsappMessageLog::withoutGlobalScopes()->create($base);
        WhatsappMessageLog::withoutGlobalScopes()->create($base);

        $this->assertSame(2, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $invoice->id)->count());
    }

    // ── Guard sesi / pelanggan ───────────────────────────────────────────

    public function test_logged_out_session_queues_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenant->id)->update([
            'customer_auto_notify_enabled' => true,
            'invoice_pdf_test_mode_enabled' => false,
            'invoice_pdf_auto_send_enabled' => true,
            'invoice_pdf_auto_send_enabled_at' => now()->subDay(),
            'invoice_pdf_auto_send_daily_cap' => 80,
        ]);
        $this->template($tenant->id);
        $this->connectedSession($tenant->id, status: WhatsappSessionStatus::LoggedOut);
        $this->eligibleInvoice($tenant->id);

        $this->runSweep();

        $this->assertSame(0, $this->queuedCount($tenant->id));
    }

    public function test_customer_without_valid_phone_is_skipped(): void
    {
        $tenant = $this->tenantWithAutoSend();
        $this->eligibleInvoice($tenant->id, phone: '');

        $this->runSweep();

        $this->assertSame(0, $this->queuedCount($tenant->id));
    }

    public function test_non_active_customer_is_skipped(): void
    {
        $tenant = $this->tenantWithAutoSend();
        $this->eligibleInvoice($tenant->id, customerStatus: CustomerStatus::Suspend);

        $this->runSweep();

        $this->assertSame(0, $this->queuedCount($tenant->id));
    }

    public function test_inactive_template_sends_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenant->id)->update([
            'customer_auto_notify_enabled' => true,
            'invoice_pdf_test_mode_enabled' => false,
            'invoice_pdf_auto_send_enabled' => true,
            'invoice_pdf_auto_send_enabled_at' => now()->subDay(),
            'invoice_pdf_auto_send_daily_cap' => 80,
        ]);
        $this->template($tenant->id, active: false);
        $this->connectedSession($tenant->id);
        $this->eligibleInvoice($tenant->id);

        $this->runSweep();

        $this->assertSame(0, $this->queuedCount($tenant->id));
    }

    // ── Dry-run ──────────────────────────────────────────────────────────

    public function test_dry_run_queues_nothing(): void
    {
        $tenant = $this->tenantWithAutoSend();
        $this->eligibleInvoice($tenant->id);

        $this->runSweep(dryRun: true);

        $this->assertSame(0, $this->queuedCount($tenant->id));
    }
}
