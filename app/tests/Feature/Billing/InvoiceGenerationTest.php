<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvalidInvoiceStatusTransitionException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Reseller;
use App\Models\ResellerTaxLedger;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use App\Services\Tax\ResellerTaxPolicyService;
use App\Services\Tax\TaxComponentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $this->actingAs($admin);

        return $admin;
    }

    public function test_generate_invoice_for_reseller_subscription_with_split_burden(): void
    {
        $admin = $this->actingAsAdmin();
        $tenant = $admin->tenant_id;
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant]);

        $ppn = app(TaxComponentService::class)->create([
            'code' => 'PPN', 'name' => 'PPN', 'type' => 'percentage', 'rate' => 11,
            'effective_from' => now()->startOfMonth()->toDateString(),
        ]);
        app(ResellerTaxPolicyService::class)->setPolicy(null, $ppn, 'customer_borne', null, now()->startOfMonth());
        app(ResellerTaxPolicyService::class)->setPolicy($reseller, $ppn, 'split', 30, now()->startOfMonth());

        $customer = Customer::factory()->create(['tenant_id' => $tenant, 'reseller_id' => $reseller->id]);
        $subscription = app(SubscriptionService::class)->create($customer, [
            'name' => 'Paket Reseller 50 Mbps',
            'monthly_amount' => 500000,
            'billing_cycle_day' => now()->day,
            'started_at' => now()->startOfMonth()->toDateString(),
        ]);

        $invoice = app(InvoiceService::class)->generateNextForSubscription($subscription);

        // 500000 * 11% = 55000 tax. Split 30% customer / 70% reseller.
        $this->assertEquals(500000.0, (float) $invoice->subtotal);
        $this->assertEquals(55000.0, (float) $invoice->tax_total);
        $this->assertEquals(555000.0, (float) $invoice->grand_total);
        $this->assertEquals($reseller->id, $invoice->reseller_id);

        $ledger = ResellerTaxLedger::where('reference_type', Invoice::class)->where('reference_id', $invoice->id)->first();
        $this->assertNotNull($ledger);
        $this->assertEquals('split', $ledger->burden_applied->value);
        $this->assertEquals(16500.0, (float) $ledger->customer_borne_amount); // 30% of 55000
        $this->assertEquals(38500.0, (float) $ledger->reseller_borne_amount); // 70% of 55000
        $this->assertEquals(
            round((float) $ledger->customer_borne_amount + (float) $ledger->reseller_borne_amount, 2),
            (float) $ledger->tax_amount
        );
    }

    public function test_generate_invoice_for_reseller_subscription_with_reseller_borne_burden(): void
    {
        $admin = $this->actingAsAdmin();
        $tenant = $admin->tenant_id;
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant]);

        $ppn = app(TaxComponentService::class)->create([
            'code' => 'PPN', 'name' => 'PPN', 'type' => 'percentage', 'rate' => 11,
            'effective_from' => now()->startOfMonth()->toDateString(),
        ]);
        app(ResellerTaxPolicyService::class)->setPolicy($reseller, $ppn, 'reseller_borne', null, now()->startOfMonth());

        $customer = Customer::factory()->create(['tenant_id' => $tenant, 'reseller_id' => $reseller->id]);
        $subscription = app(SubscriptionService::class)->create($customer, [
            'name' => 'Paket Reseller 20 Mbps',
            'monthly_amount' => 200000,
            'billing_cycle_day' => now()->day,
            'started_at' => now()->startOfMonth()->toDateString(),
        ]);

        $invoice = app(InvoiceService::class)->generateNextForSubscription($subscription);

        $this->assertEquals(22000.0, (float) $invoice->tax_total);

        $ledger = ResellerTaxLedger::where('reference_type', Invoice::class)->where('reference_id', $invoice->id)->first();
        $this->assertEquals(0.0, (float) $ledger->customer_borne_amount);
        $this->assertEquals(22000.0, (float) $ledger->reseller_borne_amount);
    }

    public function test_invoice_status_transitions_follow_the_state_machine(): void
    {
        $admin = $this->actingAsAdmin();
        $customer = Customer::factory()->create(['tenant_id' => $admin->tenant_id, 'reseller_id' => null]);
        $subscription = app(SubscriptionService::class)->create($customer, [
            'name' => 'Paket Test', 'monthly_amount' => 100000, 'billing_cycle_day' => now()->day,
            'started_at' => now()->startOfMonth()->toDateString(),
        ]);
        $invoiceService = app(InvoiceService::class);
        $invoice = $invoiceService->generateNextForSubscription($subscription);

        $this->assertEquals(InvoiceStatus::Draft, $invoice->status);

        $invoice = $invoiceService->markPending($invoice);
        $this->assertEquals(InvoiceStatus::Pending, $invoice->status);

        $invoice = $invoiceService->markOverdue($invoice);
        $this->assertEquals(InvoiceStatus::Overdue, $invoice->status);

        $invoice = $invoiceService->markPaid($invoice);
        $this->assertEquals(InvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);

        $this->expectException(InvalidInvoiceStatusTransitionException::class);
        $invoiceService->cancel($invoice);
    }

    public function test_draft_cannot_go_directly_to_paid(): void
    {
        $admin = $this->actingAsAdmin();
        $customer = Customer::factory()->create(['tenant_id' => $admin->tenant_id, 'reseller_id' => null]);
        $subscription = app(SubscriptionService::class)->create($customer, [
            'name' => 'Paket Test', 'monthly_amount' => 100000, 'billing_cycle_day' => now()->day,
            'started_at' => now()->startOfMonth()->toDateString(),
        ]);
        $invoiceService = app(InvoiceService::class);
        $invoice = $invoiceService->generateNextForSubscription($subscription);

        $this->expectException(InvalidInvoiceStatusTransitionException::class);
        $invoiceService->markPaid($invoice);
    }

    public function test_generate_due_invoices_command_only_generates_for_subscriptions_due_in_lead_days(): void
    {
        $admin = $this->actingAsAdmin();
        $tenant = $admin->tenant_id;

        // Due in exactly 7 days — should generate.
        $dueSoon = now()->addDays(7);
        $customerA = Customer::factory()->create(['tenant_id' => $tenant, 'reseller_id' => null]);
        app(SubscriptionService::class)->create($customerA, [
            'name' => 'Due Soon', 'monthly_amount' => 100000, 'billing_cycle_day' => $dueSoon->day,
            'started_at' => now()->toDateString(),
        ]);

        // Due in 20 days — should NOT generate yet.
        $dueLater = now()->addDays(20);
        $customerB = Customer::factory()->create(['tenant_id' => $tenant, 'reseller_id' => null]);
        app(SubscriptionService::class)->create($customerB, [
            'name' => 'Due Later', 'monthly_amount' => 100000, 'billing_cycle_day' => $dueLater->day,
            'started_at' => now()->toDateString(),
        ]);

        $this->artisan('app:generate-due-invoices')->assertSuccessful();

        $this->assertDatabaseHas('invoices', ['subscription_id' => Subscription::where('name', 'Due Soon')->first()->id]);
        $this->assertDatabaseMissing('invoices', ['subscription_id' => Subscription::where('name', 'Due Later')->first()->id]);

        // Auto-issued to pending, not left as draft.
        $generated = Invoice::whereHas('subscription', fn ($q) => $q->where('name', 'Due Soon'))->first();
        $this->assertEquals(InvoiceStatus::Pending, $generated->status);
    }

    public function test_mark_overdue_invoices_command_transitions_pending_past_due_invoices(): void
    {
        $admin = $this->actingAsAdmin();
        $customer = Customer::factory()->create(['tenant_id' => $admin->tenant_id, 'reseller_id' => null]);
        $subscription = app(SubscriptionService::class)->create($customer, [
            'name' => 'Paket Overdue Test', 'monthly_amount' => 100000, 'billing_cycle_day' => now()->day,
            'started_at' => now()->startOfMonth()->toDateString(),
        ]);
        $invoiceService = app(InvoiceService::class);
        $invoice = $invoiceService->generateNextForSubscription($subscription);
        $invoice = $invoiceService->markPending($invoice);
        $invoice->update(['due_date' => now()->subDays(3)->toDateString()]);

        $this->artisan('app:mark-overdue-invoices')->assertSuccessful();

        $this->assertEquals(InvoiceStatus::Overdue, $invoice->fresh()->status);
    }
}
