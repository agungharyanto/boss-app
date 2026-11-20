<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WhatsappEventType;
use App\Exceptions\InvalidInvoiceStatusTransitionException;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Services\InvoiceService;
use App\Support\Billing\ManualPaymentInput;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * v0.13.5.5 — kontrak baru InvoiceService::markPaid():
 *  - T3: TIDAK pernah mengirim WA payment_received otomatis (apa pun kondisinya).
 *  - T4: menulis SATU baris payments LUNAS HANYA bila diberi ManualPaymentInput
 *    ber-metode; idempoten; Perpanjang/migrasi (tanpa input) tidak menulis baris.
 */
class InvoiceMarkPaidTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @return array{0: Invoice, 1: Tenant, 2: Customer, 3: User} */
    private function pendingInvoice(): array
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $this->actingAs($admin);

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null,
        ]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'reseller_id' => null,
            'grand_total' => 100000,
            'status' => InvoiceStatus::Pending->value,
        ]);

        return [$invoice, $tenant, $customer, $admin];
    }

    public function test_mark_paid_never_sends_whatsapp_even_with_payment_received_template(): void
    {
        Bus::fake();
        [$invoice, $tenant] = $this->pendingInvoice();

        // Template payment_received AKTIF — tetap tidak boleh ada kiriman.
        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id, 'reseller_id' => null,
            'event_type' => WhatsappEventType::PaymentReceived,
        ]);

        app(InvoiceService::class)->markPaid($invoice);

        Bus::assertNotDispatched(SendWhatsappMessageJob::class);
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()
            ->where('event_type', WhatsappEventType::PaymentReceived->value)->count());
    }

    public function test_mark_paid_without_input_writes_no_payment_row(): void
    {
        [$invoice] = $this->pendingInvoice();

        $result = app(InvoiceService::class)->markPaid($invoice);

        $this->assertSame(InvoiceStatus::Paid, $result->status);
        $this->assertNotNull($result->paid_at);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_mark_paid_with_manual_input_records_one_paid_payment_row(): void
    {
        [$invoice, , , $admin] = $this->pendingInvoice();

        app(InvoiceService::class)->markPaid($invoice, new ManualPaymentInput(
            method: PaymentMethod::ManualTransfer,
            recordedBy: $admin->id,
            referenceNote: 'TF BCA 0812',
            companyBankAccountId: null,
        ));

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'tenant_id' => $invoice->tenant_id,
            'method' => PaymentMethod::ManualTransfer->value,
            'recorded_by' => $admin->id,
            'reference_note' => 'TF BCA 0812',
            'xendit_reference_id' => null,
            'channel_type' => null,
            'status' => PaymentStatus::Paid->value,
            'amount' => '100000.00',
        ]);
    }

    public function test_mark_paid_is_idempotent_second_call_blocked_leaves_one_row(): void
    {
        [$invoice, , , $admin] = $this->pendingInvoice();
        $service = app(InvoiceService::class);

        $service->markPaid($invoice, new ManualPaymentInput(method: PaymentMethod::ManualTransfer, recordedBy: $admin->id));
        $this->assertDatabaseCount('payments', 1);

        // Transisi Paid→Paid ditolak → tidak mungkin menulis baris kedua.
        try {
            $service->markPaid($invoice->fresh(), new ManualPaymentInput(method: PaymentMethod::ManualTransfer, recordedBy: $admin->id));
            $this->fail('Transisi Paid->Paid seharusnya ditolak.');
        } catch (InvalidInvoiceStatusTransitionException) {
            // expected
        }

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_mark_paid_with_method_null_input_writes_no_row(): void
    {
        [$invoice] = $this->pendingInvoice();

        app(InvoiceService::class)->markPaid($invoice, new ManualPaymentInput(method: null, referenceNote: 'diabaikan'));

        $this->assertDatabaseCount('payments', 0);
    }
}
