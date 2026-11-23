<?php

namespace Tests\Feature\Whatsapp;

use App\Console\Commands\SendInvoicePdfAutomatically;
use App\Console\Commands\SendWhatsappDueReminders;
use App\Console\Commands\SendWhatsappSuspendedReminders;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\WhatsappEventType;
use App\Enums\WhatsappSessionStatus;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Customer;
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
use App\Services\RegistrationService;
use App\Services\Whatsapp\WhatsappGatewayService;
use App\Support\Whatsapp\InvoiceSendContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * v0.28.1 (T1) — guard opt-out per-pelanggan di WhatsappGatewayService::
 * buildAndQueue() (jalur OTOMATIS, context null). Kirim MANUAL (InvoiceSendContext)
 * TIDAK terblokir. Plus JAMINAN RILIS: dengan saklar default (semua kirim
 * otomatis OFF, Mode Uji ON) keempat jalur otomatis dari A2 tidak mengirim apa pun.
 */
class CustomerOptOutGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function template(int $tenantId, WhatsappEventType $event): void
    {
        WhatsappMessageTemplate::create([
            'tenant_id' => $tenantId,
            'reseller_id' => null,
            'event_type' => $event->value,
            'content' => 'Halo (uji) — '.$event->value,
            'is_active' => true,
        ]);
    }

    // ── Guard opt-out di buildAndQueue (context null) ────────────────────

    public function test_opt_out_blocks_automatic_send_no_log_no_job_and_off_sends(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id, WhatsappEventType::CustomerRegistered);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id, 'reseller_id' => null, 'auto_notify_opt_out' => true,
        ]);
        // Saklar tenant ON supaya yang memblokir murni opt-out (bukan saklar).
        WorkOrderDispatchSettings::forTenant($tenant->id);

        $result = app(WhatsappGatewayService::class)->buildAndQueue(WhatsappEventType::CustomerRegistered, $customer);

        $this->assertNull($result);
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();

        // Bukti opt-out-lah penyebabnya: matikan opt-out → terkirim. v0.28.2 —
        // gerbang menuntut sesi Connected + dalam jendela jam.
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 9, 0, 0, 'Asia/Jakarta'));
        WhatsappSession::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'status' => WhatsappSessionStatus::Connected]);
        $customer->update(['auto_notify_opt_out' => false]);
        $result = app(WhatsappGatewayService::class)->buildAndQueue(WhatsappEventType::CustomerRegistered, $customer);

        $this->assertNotNull($result);
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->count());
        Queue::assertPushed(SendWhatsappMessageJob::class);
    }

    public function test_opt_out_blocks_all_four_automatic_customer_events(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id, 'reseller_id' => null, 'auto_notify_opt_out' => true,
        ]);
        WorkOrderDispatchSettings::forTenant($tenant->id); // ON

        foreach ([
            WhatsappEventType::InvoiceIssued,
            WhatsappEventType::InvoiceDueReminder,
            WhatsappEventType::CustomerSuspendedReminder,
            WhatsappEventType::CustomerRegistered,
        ] as $event) {
            $this->assertNull(app(WhatsappGatewayService::class)->buildAndQueue($event, $customer));
        }

        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_opt_out_does_not_block_manual_send_with_context(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id, WhatsappEventType::InvoiceIssued);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id, 'reseller_id' => null,
            'auto_notify_opt_out' => true, 'phone_number' => '6287884374939',
        ]);
        // Bahkan saklar tenant OFF — konteks MANUAL tetap melewati keduanya.
        WorkOrderDispatchSettings::forTenant($tenant->id)->update(['customer_auto_notify_enabled' => false]);

        $context = new InvoiceSendContext(sentByUserId: null);
        $log = app(WhatsappGatewayService::class)->buildAndQueue(
            WhatsappEventType::InvoiceIssued, $customer, null, null, $context,
        );

        $this->assertNotNull($log);
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->count());
        Queue::assertPushed(SendWhatsappMessageJob::class);
    }

    // ── JAMINAN RILIS: saklar default OFF + Mode Uji ON → nol kirim ──────

    public function test_release_default_switches_off_send_nothing_across_all_four_paths(): void
    {
        Queue::fake();
        Http::fake();
        // Jam 09:00 WIB (dalam jendela sweep) supaya gate jam bukan alasan skip.
        Carbon::setTestNow(Carbon::create(2026, 11, 10, 9, 0, 0, 'Asia/Jakarta'));
        // Jadwal due-reminder = menit sekarang → isScheduledNow() lolos, badan
        // command genuinely berjalan (bukan early-return jadwal).
        WhatsappGatewaySettings::current()->update(['daily_schedule_times' => [now()->format('H:i')]]);

        $tenant = Tenant::factory()->create();
        // Saklar PRODUKSI tenant 1 (di-set eksplisit — bukan default skema):
        // notifikasi pelanggan OFF, kirim-otomatis OFF, Mode Uji ON.
        WorkOrderDispatchSettings::forTenant($tenant->id)->update([
            'customer_auto_notify_enabled' => false,
            'invoice_pdf_auto_send_enabled' => false,
            'invoice_pdf_test_mode_enabled' => true,
            'invoice_pdf_test_recipient' => '6287884374939',
            'invoice_pdf_auto_send_enabled_at' => now()->subDay(),
        ]);

        // Template keempat event AKTIF — supaya "nol kirim" bukan karena
        // template hilang, melainkan karena saklar.
        foreach ([
            WhatsappEventType::InvoiceIssued,
            WhatsappEventType::InvoiceDueReminder,
            WhatsappEventType::CustomerSuspendedReminder,
            WhatsappEventType::CustomerRegistered,
        ] as $event) {
            $this->template($tenant->id, $event);
        }

        // Kandidat NYATA (kalau saklar ON pasti terkirim): invoice Pending due
        // hari ini + pelanggan suspend.
        $active = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'status' => CustomerStatus::Aktif, 'phone_number' => '6281234567890']);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $active->id, 'reseller_id' => null]);
        Invoice::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $active->id, 'subscription_id' => $subscription->id,
            'reseller_id' => null, 'status' => InvoiceStatus::Pending->value,
            'due_date' => now()->toDateString(), 'generated_at' => now()->subHours(2),
        ]);
        Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'status' => CustomerStatus::Suspend->value, 'phone_number' => '6281234567891']);

        // Jalankan KEEMPAT jalur otomatis A2.
        $this->artisan(SendInvoicePdfAutomatically::class)->assertExitCode(0);
        $this->artisan(SendWhatsappDueReminders::class)->assertExitCode(0);
        $this->artisan(SendWhatsappSuspendedReminders::class)->assertExitCode(0);

        $actor = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($actor);
        app(RegistrationService::class)->register([
            'name' => 'TEST Opt-out Rilis',
            'address' => 'Alamat uji',
            'phone_number' => '6281234567892',
        ]);

        // NOL kirim apa pun — DAN nol baris keputusan gerbang (B6 v0.28.2:
        // saklar default OFF → SkippedAutoNotifyOff tidak dicatat).
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());
        $this->assertSame(0, CustomerAutoSendDecisionLog::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();

        Carbon::setTestNow();
    }
}
