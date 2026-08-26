<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\Payment\PaymentService;
use App\Services\Payment\XenditGatewayService;
use App\Services\SubscriptionService;
use Database\Seeders\PaymentGatewayChannelSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class PaymentServiceSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_xendit_gateway_refuses_to_operate_if_production_flag_mismatches_app_environment(): void
    {
        Config::set('services.xendit.is_production', true);
        // Test environment is never 'production' (see phpunit.xml).

        $this->expectException(RuntimeException::class);
        app(XenditGatewayService::class);
    }

    public function test_non_billing_non_admin_cannot_create_payment(): void
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
        $invoice = app(InvoiceService::class)->generateNextForSubscription($subscription);

        $nonAdmin = User::factory()->create(['tenant_id' => $tenant->id]);
        $nonAdmin->assignRole('customer_service');

        $this->actingAs($nonAdmin)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments", ['channel_type' => 'virtual_account'])
            ->assertForbidden();
    }

    public function test_invalid_channel_type_is_rejected_by_validation(): void
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
        $invoice = app(InvoiceService::class)->generateNextForSubscription($subscription);

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", ['channel_type' => 'bitcoin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['channel_type']);
    }

    public function test_create_payment_for_rejects_a_channel_that_exists_in_the_catalog_but_is_disabled(): void
    {
        $this->seed(PaymentGatewayChannelSeeder::class);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $this->actingAs($admin);

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = app(SubscriptionService::class)->create($customer, [
            'name' => 'Paket Test', 'monthly_amount' => 100000, 'billing_cycle_day' => now()->day,
            'started_at' => now()->startOfMonth()->toDateString(),
        ]);
        $invoice = app(InvoiceService::class)->generateNextForSubscription($subscription);

        // QRIS exists in the catalog (seeded above) but is disabled by
        // default — nobody has enabled it via Pengaturan > Payment Gateway.
        $this->expectException(InvalidArgumentException::class);

        app(PaymentService::class)->createPaymentFor($invoice, 'QRIS');
    }
}
