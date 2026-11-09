<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\WhatsappEventType;
use App\Enums\WhatsappSessionStatus;
use App\Livewire\Billing\InvoiceIndex;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * v0.13.5.3 — UI kirim PDF invoice (modal, banner Mode Uji, flash, izin,
 * penanda status). Nol kirim nyata.
 */
class InvoiceIndexSendPdfLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');

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

    private function manager(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'invoices.manage', 'guard_name' => 'web']));
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'invoices.view', 'guard_name' => 'web']));

        return $user;
    }

    private function viewer(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'invoices.view', 'guard_name' => 'web']));

        return $user;
    }

    private function seedInvoice(Tenant $tenant): Invoice
    {
        WhatsappMessageTemplate::create([
            'tenant_id' => $tenant->id, 'reseller_id' => null,
            'event_type' => WhatsappEventType::InvoiceIssued->value,
            'content' => 'Halo {customer_name} — {invoice_number}', 'is_active' => true,
        ]);
        WhatsappSession::factory()->create([
            'tenant_id' => $tenant->id, 'reseller_id' => null,
            'status' => WhatsappSessionStatus::Connected,
        ]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'phone_number' => '081234567890']);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);

        return Invoice::factory()->create([
            'tenant_id' => $tenant->id, 'subscription_id' => $subscription->id,
            'customer_id' => $customer->id, 'reseller_id' => null, 'status' => InvoiceStatus::Pending,
        ]);
    }

    public function test_confirm_opens_modal_with_preview(): void
    {
        $tenant = Tenant::factory()->create();
        $invoice = $this->seedInvoice($tenant);

        Livewire::actingAs($this->manager($tenant))
            ->test(InvoiceIndex::class)
            ->call('confirmSendPdf', $invoice->id)
            ->assertSet('sendPdfInvoiceId', $invoice->id)
            ->assertSee('6281234567890');
    }

    public function test_test_mode_shows_red_banner_with_test_number(): void
    {
        $tenant = Tenant::factory()->create();
        $invoice = $this->seedInvoice($tenant);
        WorkOrderDispatchSettings::forTenant($tenant->id)->update([
            'invoice_pdf_test_mode_enabled' => true,
            'invoice_pdf_test_recipient' => '6287884374939',
        ]);

        Livewire::actingAs($this->manager($tenant))
            ->test(InvoiceIndex::class)
            ->call('confirmSendPdf', $invoice->id)
            ->assertSee('MODE UJI')
            ->assertSee('6287884374939');
    }

    public function test_send_queues_message_and_flashes(): void
    {
        $tenant = Tenant::factory()->create();
        $invoice = $this->seedInvoice($tenant);

        Livewire::actingAs($this->manager($tenant))
            ->test(InvoiceIndex::class)
            ->call('confirmSendPdf', $invoice->id)
            ->call('sendPdf', false)
            ->assertSet('sendPdfInvoiceId', null);

        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('invoice_id', $invoice->id)->count());
    }

    public function test_error_is_surfaced_not_thrown(): void
    {
        $tenant = Tenant::factory()->create();
        $invoice = $this->seedInvoice($tenant);
        $invoice->update(['status' => InvoiceStatus::Draft]);

        Livewire::actingAs($this->manager($tenant))
            ->test(InvoiceIndex::class)
            ->call('confirmSendPdf', $invoice->id)
            ->call('sendPdf', false)
            ->assertSet('sendPdfError', fn ($v) => is_string($v) && $v !== '');
    }

    public function test_viewer_without_manage_cannot_confirm_send(): void
    {
        $tenant = Tenant::factory()->create();
        $invoice = $this->seedInvoice($tenant);

        Livewire::actingAs($this->viewer($tenant))
            ->test(InvoiceIndex::class)
            ->call('confirmSendPdf', $invoice->id)
            ->assertForbidden();
    }
}
