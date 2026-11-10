<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\WhatsappEventType;
use App\Enums\WhatsappMessageStatus;
use App\Enums\WhatsappSessionStatus;
use App\Exceptions\InvoicePdfSendException;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Billing\InvoicePdfSendService;
use App\Services\Billing\InvoicePdfService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * v0.13.5.3 — penegakan kirim PDF invoice MANUAL. Nol kirim nyata
 * (Queue::fake + InvoicePdfService dipalsukan supaya tidak render dompdf).
 */
class InvoicePdfSendServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');

        // Palsukan render/store PDF — tidak menjalankan dompdf; cukup menulis
        // file dummy ke disk 'local' palsu.
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

    private function actor(int $tenantId, bool $withPermission = true): User
    {
        $user = User::factory()->create(['tenant_id' => $tenantId]);
        if ($withPermission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => 'invoices.manage', 'guard_name' => 'web']));
        }

        return $user;
    }

    private function template(int $tenantId, bool $active = true): void
    {
        WhatsappMessageTemplate::create([
            'tenant_id' => $tenantId,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::InvoiceIssued->value,
            'content' => 'Halo {customer_name}, tagihan {invoice_number} total {total_amount}. CS {cs_number} — {company_name}',
            'is_active' => $active,
        ]);
    }

    private function connectedSession(int $tenantId, ?int $resellerId = null): void
    {
        WhatsappSession::factory()->create([
            'tenant_id' => $tenantId,
            'reseller_id' => $resellerId,
            'status' => WhatsappSessionStatus::Connected,
        ]);
    }

    private function invoice(int $tenantId, InvoiceStatus $status = InvoiceStatus::Pending, string $phone = '081234567890'): Invoice
    {
        $customer = Customer::factory()->create([
            'tenant_id' => $tenantId,
            'reseller_id' => null,
            'phone_number' => $phone,
        ]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'reseller_id' => null,
        ]);

        return Invoice::factory()->create([
            'tenant_id' => $tenantId,
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'reseller_id' => null,
            'status' => $status,
        ]);
    }

    private function enableTestMode(int $tenantId, ?string $recipient = '6287884374939'): void
    {
        WorkOrderDispatchSettings::forTenant($tenantId)->update([
            'invoice_pdf_test_mode_enabled' => true,
            'invoice_pdf_test_recipient' => $recipient,
        ]);
    }

    // ── Happy path ───────────────────────────────────────────────────────

    public function test_sends_one_message_with_pdf_attachment(): void
    {
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id);
        $this->connectedSession($tenant->id);
        $invoice = $this->invoice($tenant->id);
        $actor = $this->actor($tenant->id);

        $log = app(InvoicePdfSendService::class)->sendManually($invoice, $actor);

        $this->assertSame(WhatsappEventType::InvoiceIssued, $log->event_type);
        $this->assertFalse($log->is_test);
        $this->assertSame($actor->id, $log->sent_by_user_id);
        $this->assertSame('6281234567890', $log->phone_number);
        $this->assertSame('application/pdf', $log->attachment['mime']);
        $this->assertSame("invoice-pdfs/{$tenant->id}/{$invoice->id}.pdf", $log->attachment['path']);
        $this->assertSame(WhatsappMessageStatus::Queued, $log->status);

        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->count());
        Queue::assertPushed(SendWhatsappMessageJob::class, 1);
    }

    public function test_manual_send_bypasses_customer_auto_notify_switch_off(): void
    {
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id);
        $this->connectedSession($tenant->id);
        WorkOrderDispatchSettings::forTenant($tenant->id)->update(['customer_auto_notify_enabled' => false]);
        $invoice = $this->invoice($tenant->id);

        $log = app(InvoicePdfSendService::class)->sendManually($invoice, $this->actor($tenant->id));

        $this->assertNotNull($log);
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->count());
        Queue::assertPushed(SendWhatsappMessageJob::class, 1);
    }

    // ── Status ───────────────────────────────────────────────────────────

    public function test_draft_invoice_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id);
        $this->connectedSession($tenant->id);
        $invoice = $this->invoice($tenant->id, InvoiceStatus::Draft);

        $this->expectException(InvoicePdfSendException::class);
        app(InvoicePdfSendService::class)->sendManually($invoice, $this->actor($tenant->id));
    }

    public function test_cancelled_invoice_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id);
        $this->connectedSession($tenant->id);
        $invoice = $this->invoice($tenant->id, InvoiceStatus::Cancelled);

        $this->expectException(InvoicePdfSendException::class);
        app(InvoicePdfSendService::class)->sendManually($invoice, $this->actor($tenant->id));
    }

    public function test_paid_and_overdue_are_allowed(): void
    {
        foreach ([InvoiceStatus::Paid, InvoiceStatus::Overdue] as $status) {
            $tenant = Tenant::factory()->create();
            $this->template($tenant->id);
            $this->connectedSession($tenant->id);
            $invoice = $this->invoice($tenant->id, $status);

            $log = app(InvoicePdfSendService::class)->sendManually($invoice, $this->actor($tenant->id));
            $this->assertNotNull($log);
        }
    }

    // ── Nomor / template / sesi ──────────────────────────────────────────

    public function test_empty_customer_phone_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id);
        $this->connectedSession($tenant->id);
        $invoice = $this->invoice($tenant->id);
        $invoice->customer->update(['phone_number' => '']);
        $invoice->load('customer');

        $this->expectException(InvoicePdfSendException::class);
        app(InvoicePdfSendService::class)->sendManually($invoice, $this->actor($tenant->id));
    }

    public function test_inactive_template_is_rejected_clearly(): void
    {
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id, active: false);
        $this->connectedSession($tenant->id);
        $invoice = $this->invoice($tenant->id);

        $this->expectException(InvoicePdfSendException::class);
        app(InvoicePdfSendService::class)->sendManually($invoice, $this->actor($tenant->id));
    }

    public function test_session_not_connected_is_not_queued(): void
    {
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id);
        WhatsappSession::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'status' => WhatsappSessionStatus::LoggedOut,
        ]);
        $invoice = $this->invoice($tenant->id);

        try {
            app(InvoicePdfSendService::class)->sendManually($invoice, $this->actor($tenant->id));
            $this->fail('Expected InvoicePdfSendException');
        } catch (InvoicePdfSendException) {
            // ok
        }

        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    // ── Kirim ulang / anti-spam ──────────────────────────────────────────

    public function test_second_send_without_resend_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id);
        $this->connectedSession($tenant->id);
        $invoice = $this->invoice($tenant->id);
        $service = app(InvoicePdfSendService::class);

        $service->sendManually($invoice, $this->actor($tenant->id));

        $this->expectException(InvoicePdfSendException::class);
        $service->sendManually($invoice, $this->actor($tenant->id), resend: false);
    }

    public function test_resend_within_cooldown_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id);
        $this->connectedSession($tenant->id);
        $invoice = $this->invoice($tenant->id);
        $service = app(InvoicePdfSendService::class);

        $first = $service->sendManually($invoice, $this->actor($tenant->id));
        // Baris baru saja dibuat (created_at ~ now) → masih dalam 10 menit.
        $first->forceFill(['created_at' => now()->subMinutes(3)])->save();

        $this->expectException(InvoicePdfSendException::class);
        $service->sendManually($invoice, $this->actor($tenant->id), resend: true);
    }

    public function test_resend_after_cooldown_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id);
        $this->connectedSession($tenant->id);
        $invoice = $this->invoice($tenant->id);
        $service = app(InvoicePdfSendService::class);

        $first = $service->sendManually($invoice, $this->actor($tenant->id));
        $first->forceFill(['created_at' => now()->subMinutes(11)])->save();

        $second = $service->sendManually($invoice, $this->actor($tenant->id), resend: true);
        $this->assertNotNull($second);
        $this->assertSame(2, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $invoice->id)->count());
    }

    public function test_a_prior_test_log_does_not_block_the_first_real_send(): void
    {
        // v0.13.5.3.1 D7 — gate "sudah pernah dikirim" pakai includeTest:false,
        // jadi log UJI tidak memblokir kirim pertama NYATA (tanpa resend).
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id);
        $this->connectedSession($tenant->id);
        $this->enableTestMode($tenant->id, '6287884374939');
        $invoice = $this->invoice($tenant->id, phone: '081111111111');
        $service = app(InvoicePdfSendService::class);

        $test = $service->sendManually($invoice, $this->actor($tenant->id));
        $this->assertTrue($test->is_test);

        WorkOrderDispatchSettings::forTenant($tenant->id)->update(['invoice_pdf_test_mode_enabled' => false]);

        $real = $service->sendManually($invoice, $this->actor($tenant->id), resend: false);
        $this->assertFalse($real->is_test);
    }

    // ── Mode Uji ─────────────────────────────────────────────────────────

    public function test_test_mode_redirects_to_test_number_marks_is_test_and_prefixes_caption(): void
    {
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id);
        $this->connectedSession($tenant->id);
        $this->enableTestMode($tenant->id, '087884374939');
        $invoice = $this->invoice($tenant->id, phone: '081111111111');

        $log = app(InvoicePdfSendService::class)->sendManually($invoice, $this->actor($tenant->id));

        $this->assertTrue($log->is_test);
        $this->assertSame('6287884374939', $log->phone_number);
        $this->assertStringStartsWith('[UJI untuk:', $log->rendered_content);
        $this->assertStringContainsString($invoice->invoice_number, $log->rendered_content);
    }

    public function test_test_mode_send_does_not_count_as_sent_to_customer(): void
    {
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id);
        $this->connectedSession($tenant->id);
        $this->enableTestMode($tenant->id);
        $invoice = $this->invoice($tenant->id);

        app(InvoicePdfSendService::class)->sendManually($invoice, $this->actor($tenant->id));

        $preview = app(InvoicePdfSendService::class)->previewFor($invoice->fresh()->load('customer'));
        $this->assertNull($preview['already_sent_at']);
        $this->assertTrue($preview['last_is_test']);
    }

    public function test_test_mode_without_valid_number_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id);
        $this->connectedSession($tenant->id);
        $this->enableTestMode($tenant->id, recipient: null);
        $invoice = $this->invoice($tenant->id);

        $this->expectException(InvoicePdfSendException::class);
        app(InvoicePdfSendService::class)->sendManually($invoice, $this->actor($tenant->id));
    }

    public function test_test_mode_off_sends_to_real_customer(): void
    {
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id);
        $this->connectedSession($tenant->id);
        $invoice = $this->invoice($tenant->id, phone: '081999888777');

        $log = app(InvoicePdfSendService::class)->sendManually($invoice, $this->actor($tenant->id));

        $this->assertFalse($log->is_test);
        $this->assertSame('6281999888777', $log->phone_number);
    }

    // ── Izin ─────────────────────────────────────────────────────────────

    public function test_actor_without_permission_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $this->template($tenant->id);
        $this->connectedSession($tenant->id);
        $invoice = $this->invoice($tenant->id);

        $this->expectException(AuthorizationException::class);
        app(InvoicePdfSendService::class)->sendManually($invoice, $this->actor($tenant->id, withPermission: false));
    }
}
