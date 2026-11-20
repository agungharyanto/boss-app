<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\WhatsappEventType;
use App\Enums\WhatsappSessionStatus;
use App\Livewire\Billing\InvoiceIndex;
use App\Models\CompanyBankAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappSession;
use App\Services\Billing\InvoicePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * v0.13.5.5 — UI "Tandai Lunas" (T4, modal metode/rekening/catatan) + "Kirim
 * Kuitansi via WhatsApp" (T3, modal Mode Uji). Nol kirim nyata.
 */
class InvoiceMarkPaidAndReceiptLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');

        // Fake PDF service: storeReceipt() & store() cuma menulis file dummy
        // (tidak menjalankan dompdf/GD di test).
        $this->app->bind(InvoicePdfService::class, fn () => new class extends InvoicePdfService
        {
            public function __construct() {}

            public function store(Invoice $invoice): string
            {
                $path = "invoice-pdfs/{$invoice->tenant_id}/{$invoice->id}.pdf";
                Storage::disk('local')->put($path, '%PDF-1.4 dummy');

                return $path;
            }

            public function storeReceipt(Invoice $invoice): string
            {
                $path = "invoice-pdfs/{$invoice->tenant_id}/{$invoice->id}-receipt.pdf";
                Storage::disk('local')->put($path, '%PDF-1.4 dummy');

                return $path;
            }
        });
    }

    private function manager(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'invoices.manage', 'guard_name' => 'web']));
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'invoices.view', 'guard_name' => 'web']));

        return $user;
    }

    private function pendingInvoice(Tenant $tenant): Invoice
    {
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'phone_number' => '081234567890']);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);

        return Invoice::factory()->create([
            'tenant_id' => $tenant->id, 'subscription_id' => $subscription->id,
            'customer_id' => $customer->id, 'reseller_id' => null,
            'grand_total' => 120000, 'status' => InvoiceStatus::Pending,
        ]);
    }

    // ── T4: Tandai Lunas ─────────────────────────────────────────────────

    public function test_confirm_mark_paid_opens_modal_with_default_manual_and_default_bank(): void
    {
        $tenant = Tenant::factory()->create();
        $invoice = $this->pendingInvoice($tenant);
        $bank = CompanyBankAccount::create([
            'tenant_id' => $tenant->id, 'bank_name' => 'BCA', 'account_number' => '123',
            'account_holder' => 'PT X', 'is_default' => true, 'is_active' => true,
        ]);

        Livewire::actingAs($this->manager($tenant))
            ->test(InvoiceIndex::class)
            ->call('confirmMarkPaid', $invoice->id)
            ->assertSet('markPaidInvoiceId', $invoice->id)
            ->assertSet('markPaidMethod', 'manual_transfer')
            ->assertSet('markPaidBankAccountId', $bank->id);
    }

    public function test_submit_mark_paid_records_a_manual_payment_row_and_flashes(): void
    {
        $tenant = Tenant::factory()->create();
        $invoice = $this->pendingInvoice($tenant);
        $bank = CompanyBankAccount::create([
            'tenant_id' => $tenant->id, 'bank_name' => 'BCA', 'account_number' => '123',
            'account_holder' => 'PT X', 'is_default' => true, 'is_active' => true,
        ]);
        $manager = $this->manager($tenant);

        Livewire::actingAs($manager)
            ->test(InvoiceIndex::class)
            ->call('confirmMarkPaid', $invoice->id)
            ->set('markPaidReferenceNote', 'TF 0812')
            ->call('submitMarkPaid')
            ->assertSet('markPaidInvoiceId', null);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'method' => 'manual_transfer',
            'recorded_by' => $manager->id,
            'reference_note' => 'TF 0812',
            'company_bank_account_id' => $bank->id,
            'status' => PaymentStatus::Paid->value,
            'amount' => '120000.00',
        ]);
    }

    public function test_submit_mark_paid_non_manual_method_stores_no_bank_account(): void
    {
        $tenant = Tenant::factory()->create();
        $invoice = $this->pendingInvoice($tenant);
        CompanyBankAccount::create([
            'tenant_id' => $tenant->id, 'bank_name' => 'BCA', 'account_number' => '123',
            'account_holder' => 'PT X', 'is_default' => true, 'is_active' => true,
        ]);

        Livewire::actingAs($this->manager($tenant))
            ->test(InvoiceIndex::class)
            ->call('confirmMarkPaid', $invoice->id)
            ->set('markPaidMethod', 'agent')
            ->call('submitMarkPaid');

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'method' => 'agent',
            'company_bank_account_id' => null,
        ]);
    }

    // ── T3: Kirim Kuitansi ───────────────────────────────────────────────

    private function paidInvoiceWithWaReady(Tenant $tenant): Invoice
    {
        WhatsappMessageTemplate::create([
            'tenant_id' => $tenant->id, 'reseller_id' => null,
            'event_type' => WhatsappEventType::PaymentReceived->value,
            'content' => 'Pembayaran {invoice_number} diterima {paid_date} — {company_name}', 'is_active' => true,
        ]);
        WhatsappSession::factory()->create([
            'tenant_id' => $tenant->id, 'reseller_id' => null,
            'status' => WhatsappSessionStatus::Connected,
        ]);
        $invoice = $this->pendingInvoice($tenant);
        $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);

        return $invoice->fresh();
    }

    public function test_confirm_send_receipt_opens_modal_for_paid_invoice(): void
    {
        $tenant = Tenant::factory()->create();
        $invoice = $this->paidInvoiceWithWaReady($tenant);

        Livewire::actingAs($this->manager($tenant))
            ->test(InvoiceIndex::class)
            ->call('confirmSendReceipt', $invoice->id)
            ->assertSet('sendReceiptInvoiceId', $invoice->id)
            ->assertSee('6281234567890');
    }

    public function test_send_receipt_queues_a_payment_received_log(): void
    {
        $tenant = Tenant::factory()->create();
        $invoice = $this->paidInvoiceWithWaReady($tenant);

        Livewire::actingAs($this->manager($tenant))
            ->test(InvoiceIndex::class)
            ->call('confirmSendReceipt', $invoice->id)
            ->call('sendReceipt', false)
            ->assertSet('sendReceiptInvoiceId', null);

        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()
            ->where('invoice_id', $invoice->id)
            ->where('event_type', WhatsappEventType::PaymentReceived->value)
            ->count());
    }

    public function test_send_receipt_rejects_non_paid_invoice_with_a_surfaced_error(): void
    {
        $tenant = Tenant::factory()->create();
        $invoice = $this->paidInvoiceWithWaReady($tenant);
        $invoice->update(['status' => InvoiceStatus::Pending]);

        Livewire::actingAs($this->manager($tenant))
            ->test(InvoiceIndex::class)
            ->call('confirmSendReceipt', $invoice->id)
            ->call('sendReceipt', false)
            ->assertSet('sendReceiptError', fn ($v) => is_string($v) && $v !== '');

        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $invoice->id)->count());
    }
}
