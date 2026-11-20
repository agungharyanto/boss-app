<?php

namespace Tests\Feature\Api;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\Tax\ResellerTaxPolicyService;
use App\Services\Tax\TaxComponentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionInvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function billingUser(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('billing');

        return $user;
    }

    public function test_billing_user_can_create_subscription_and_generate_invoice(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = $this->billingUser($tenant);
        $this->actingAs($billing);

        $ppn = app(TaxComponentService::class)->create([
            'code' => 'PPN', 'name' => 'PPN', 'type' => 'percentage', 'rate' => 11,
            'effective_from' => now()->startOfMonth()->toDateString(),
        ]);
        app(ResellerTaxPolicyService::class)->setPolicy(null, $ppn, 'customer_borne', null, now()->startOfMonth());

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        $subResponse = $this->postJson('/api/v1/subscriptions', [
            'customer_id' => $customer->id,
            'name' => 'Paket 20 Mbps',
            'monthly_amount' => 200000,
            'billing_cycle_day' => now()->day,
            'started_at' => now()->startOfMonth()->toDateString(),
        ]);
        $subResponse->assertCreated();
        $subscriptionId = $subResponse->json('data.id');

        $invResponse = $this->postJson('/api/v1/invoices/generate', ['subscription_id' => $subscriptionId]);
        $invResponse->assertCreated();
        $invResponse->assertJsonPath('data.status', 'draft');
        // 200000 + 11% PPN = 222000.
        $this->assertEquals(222000.0, $invResponse->json('data.grand_total'));

        $invoiceId = $invResponse->json('data.id');

        // State machine: draft -> pending -> paid
        $this->patchJson("/api/v1/invoices/{$invoiceId}/pending")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->patchJson("/api/v1/invoices/{$invoiceId}/paid")->assertOk()->assertJsonPath('data.status', 'paid');

        // paid is terminal
        $this->patchJson("/api/v1/invoices/{$invoiceId}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * v0.13.5.5 (T4) — PATCH .../paid dengan `method` menulis satu baris
     * payments; `recorded_by` = pengguna API.
     */
    public function test_mark_paid_with_method_records_a_payment_row(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = $this->billingUser($tenant);
        $this->actingAs($billing);

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null,
        ]);
        $invoice = app(InvoiceService::class)->markPending(
            Invoice::factory()->create([
                'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
                'subscription_id' => $subscription->id, 'reseller_id' => null,
                'grand_total' => 150000, 'status' => InvoiceStatus::Draft->value,
            ])
        );

        $this->patchJson("/api/v1/invoices/{$invoice->id}/paid", [
            'method' => 'manual_transfer',
            'reference_note' => 'TF mandiri 1234',
        ])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'method' => 'manual_transfer',
            'recorded_by' => $billing->id,
            'reference_note' => 'TF mandiri 1234',
            'status' => 'paid',
            'amount' => '150000.00',
        ]);
    }

    public function test_mark_paid_without_method_records_no_payment_row(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = $this->billingUser($tenant);
        $this->actingAs($billing);

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null,
        ]);
        $invoice = app(InvoiceService::class)->markPending(
            Invoice::factory()->create([
                'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
                'subscription_id' => $subscription->id, 'reseller_id' => null,
                'status' => InvoiceStatus::Draft->value,
            ])
        );

        $this->patchJson("/api/v1/invoices/{$invoice->id}/paid")
            ->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * The /invoices/generate endpoint always advances to the NEXT unbilled
     * period (see InvoiceService::generateNextForSubscription) — calling it
     * twice in a row legitimately produces two invoices for two different
     * consecutive periods, not a duplicate. The actual "no duplicate for
     * the same period" guarantee lives in
     * InvoiceService::generateForPeriod() itself (called with the SAME
     * explicit period twice here), which every caller — including the
     * scheduled command — goes through.
     */
    public function test_generating_for_the_same_explicit_period_twice_does_not_duplicate(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = $this->billingUser($tenant);
        $this->actingAs($billing);

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subResponse = $this->postJson('/api/v1/subscriptions', [
            'customer_id' => $customer->id,
            'name' => 'Paket 10 Mbps',
            'monthly_amount' => 150000,
            'billing_cycle_day' => now()->day,
            'started_at' => now()->startOfMonth()->toDateString(),
        ]);
        $subscriptionId = $subResponse->json('data.id');
        $subscription = Subscription::find($subscriptionId);

        $invoiceService = app(InvoiceService::class);
        [$periodStart, $periodEnd, $dueDate] = $invoiceService->previewNextPeriod($subscription);

        $first = $invoiceService->generateForPeriod($subscription, $periodStart, $periodEnd, $dueDate);
        $second = $invoiceService->generateForPeriod($subscription, $periodStart, $periodEnd, $dueDate);

        $this->assertEquals($first->id, $second->id);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_non_billing_non_admin_cannot_create_subscription(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->postJson('/api/v1/subscriptions', [
            'customer_id' => $customer->id,
            'name' => 'Paket X',
            'monthly_amount' => 100000,
            'billing_cycle_day' => 10,
        ])->assertForbidden();
    }
}
