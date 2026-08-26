<?php

namespace Tests\Feature\Api;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\Payment\PaymentGatewaySettingsService;
use App\Services\SubscriptionService;
use Database\Seeders\PaymentGatewayChannelSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XenditWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PaymentGatewayChannelSeeder::class);

        // v0.3.5 Fase H: webhook token + secret key now live in
        // payment_gateway_settings (DB, encrypted), not
        // config('services.xendit.callback_token')/.env — see
        // PaymentGatewaySettingsService.
        app(PaymentGatewaySettingsService::class)->update([
            'xendit_secret_key' => 'sandbox-secret-for-tests',
            'xendit_webhook_token' => 'test-token-abc',
            'channels' => ['BRI_VA', 'QRIS', 'XENDIT_INVOICE'],
        ], User::factory()->create());
    }

    private function pendingInvoice(): Invoice
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

        return app(InvoiceService::class)->markPending($invoice);
    }

    public function test_create_payment_via_api_calls_xendit_and_stores_reference(): void
    {
        Http::fake(['api.xendit.co/*' => Http::response(['id' => 'xnd_va_abc'], 200)]);

        $invoice = $this->pendingInvoice();

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/payments", ['channel_type' => 'BRI_VA']);

        $response->assertCreated();
        $response->assertJsonPath('data.xendit_reference_id', 'xnd_va_abc');
        $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'xendit_reference_id' => 'xnd_va_abc']);
    }

    public function test_webhook_with_valid_signature_and_matching_amount_marks_invoice_paid(): void
    {
        // The webhook route sits outside auth:sanctum entirely (see
        // routes/api.php) — no session/actingAs needed to reach it, this
        // just documents that the request below is unauthenticated.
        $invoice = $this->pendingInvoice();

        $response = $this->postJson('/api/v1/webhooks/xendit', [
            'id' => 'evt_1',
            'external_id' => $invoice->invoice_number,
            'amount' => (float) $invoice->grand_total,
        ], ['x-callback-token' => 'test-token-abc']);

        $response->assertOk();
        $response->assertJsonPath('data.result', 'applied');
        $this->assertEquals(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertDatabaseHas('payment_webhook_logs', ['xendit_event_id' => 'evt_1', 'processing_result' => 'applied']);
    }

    public function test_webhook_with_invalid_signature_is_rejected_and_does_not_touch_invoice(): void
    {
        $invoice = $this->pendingInvoice();

        $response = $this->postJson('/api/v1/webhooks/xendit', [
            'id' => 'evt_2',
            'external_id' => $invoice->invoice_number,
            'amount' => (float) $invoice->grand_total,
        ], ['x-callback-token' => 'wrong-token']);

        // Webhook endpoint always answers 200 (see XenditWebhookController) —
        // the rejection is only visible in the log/result payload.
        $response->assertOk();
        $response->assertJsonPath('data.result', 'rejected_signature');
        $this->assertEquals(InvoiceStatus::Pending, $invoice->fresh()->status);
        $this->assertDatabaseHas('payment_webhook_logs', ['xendit_event_id' => 'evt_2', 'signature_valid' => false]);
    }

    public function test_duplicate_webhook_event_id_does_not_process_twice(): void
    {
        $invoice = $this->pendingInvoice();

        $payload = [
            'id' => 'evt_3',
            'external_id' => $invoice->invoice_number,
            'amount' => (float) $invoice->grand_total,
        ];

        $this->postJson('/api/v1/webhooks/xendit', $payload, ['x-callback-token' => 'test-token-abc'])
            ->assertJsonPath('data.result', 'applied');

        $this->postJson('/api/v1/webhooks/xendit', $payload, ['x-callback-token' => 'test-token-abc'])
            ->assertJsonPath('data.result', 'duplicate');

        $this->assertDatabaseCount('payment_webhook_logs', 1);
        $this->assertEquals(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_amount_mismatch_is_rejected_and_invoice_status_unchanged(): void
    {
        $invoice = $this->pendingInvoice();

        $response = $this->postJson('/api/v1/webhooks/xendit', [
            'id' => 'evt_4',
            'external_id' => $invoice->invoice_number,
            'amount' => (float) $invoice->grand_total + 1000,
        ], ['x-callback-token' => 'test-token-abc']);

        $response->assertJsonPath('data.result', 'rejected_amount_mismatch');
        $this->assertEquals(InvoiceStatus::Pending, $invoice->fresh()->status);
    }

    public function test_webhook_for_unknown_invoice_number_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/webhooks/xendit', [
            'id' => 'evt_5',
            'external_id' => 'INV/DOES-NOT-EXIST/2026/01/000001',
            'amount' => 100000,
        ], ['x-callback-token' => 'test-token-abc']);

        $response->assertJsonPath('data.result', 'rejected_invoice_not_found');
    }
}
