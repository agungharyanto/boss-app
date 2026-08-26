<?php

namespace Tests\Feature\Billing;

use App\Livewire\Billing\ReconciliationReport;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReconciliationReportLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_report_flags_paid_invoice_with_matching_payment_as_ok(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $this->actingAs($admin);

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = app(SubscriptionService::class)->create($customer, [
            'name' => 'Paket Test', 'monthly_amount' => 100000, 'billing_cycle_day' => now()->day,
            'started_at' => now()->startOfMonth()->toDateString(),
        ]);
        $invoiceService = app(InvoiceService::class);
        $invoice = $invoiceService->generateNextForSubscription($subscription);
        $invoice = $invoiceService->markPending($invoice);
        Payment::factory()->paid()->for($invoice, 'invoice')->create(['tenant_id' => $tenant->id]);
        $invoice = $invoiceService->markPaid($invoice);

        Livewire::actingAs($admin)
            ->test(ReconciliationReport::class)
            ->assertOk()
            ->assertSee($invoice->invoice_number)
            ->assertDontSee('ANOMALY');
    }

    public function test_report_flags_paid_invoice_without_matching_payment_as_anomaly(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $this->actingAs($admin);

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = app(SubscriptionService::class)->create($customer, [
            'name' => 'Paket Test', 'monthly_amount' => 100000, 'billing_cycle_day' => now()->day,
            'started_at' => now()->startOfMonth()->toDateString(),
        ]);
        $invoiceService = app(InvoiceService::class);
        $invoice = $invoiceService->generateNextForSubscription($subscription);
        $invoice = $invoiceService->markPending($invoice);
        // No Payment row created — invoice is manually marked paid without a payment record.
        $invoice = $invoiceService->markPaid($invoice);

        Livewire::actingAs($admin)
            ->test(ReconciliationReport::class)
            ->assertOk()
            ->assertSee($invoice->invoice_number)
            ->assertSee('ANOMALY');
    }

    public function test_report_lists_rejected_webhook_logs(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');

        PaymentWebhookLog::create([
            'xendit_event_id' => 'evt_anomaly_1',
            'payload' => ['external_id' => 'INV/DIRECT/2026/01/000001'],
            'signature_valid' => true,
            'processed_at' => now(),
            'processing_result' => 'rejected_amount_mismatch',
        ]);

        Livewire::actingAs($admin)
            ->test(ReconciliationReport::class)
            ->assertOk()
            ->assertSee('evt_anomaly_1');
    }

    public function test_non_admin_non_billing_cannot_mount_report(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        Livewire::actingAs($user)->test(ReconciliationReport::class)->assertForbidden();
    }
}
