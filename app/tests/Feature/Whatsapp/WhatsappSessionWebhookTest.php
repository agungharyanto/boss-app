<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\WhatsappSession;
use App\Support\WhatsappHmac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WhatsappSessionWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp_gateway.hmac_secret' => 'test-shared-secret']);
    }

    private function postSigned(array $payload): TestResponse
    {
        $body = json_encode($payload);
        $timestamp = time();
        $signature = (new WhatsappHmac('test-shared-secret'))->sign($body, $timestamp);

        return $this->call('POST', '/api/v1/whatsapp/webhook/session-status', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Whatsapp-Signature' => $signature,
            'HTTP_X-Whatsapp-Timestamp' => (string) $timestamp,
        ], $body);
    }

    public function test_valid_signature_updates_reseller_session_status(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $session = WhatsappSession::factory()->forReseller($reseller)->create();

        $response = $this->postSigned([
            'session_key' => (string) $reseller->id,
            'status' => 'connected',
            'phone_number' => '628123456789',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.result', 'applied');
        $this->assertSame('connected', $session->fresh()->status->value);
        $this->assertSame('628123456789', $session->fresh()->phone_number);
    }

    public function test_valid_signature_updates_direct_session_status(): void
    {
        $tenant = Tenant::factory()->create();
        $session = WhatsappSession::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        $response = $this->postSigned([
            'session_key' => 'direct',
            'status' => 'connected',
        ]);

        $response->assertJsonPath('data.result', 'applied');
        $this->assertSame('connected', $session->fresh()->status->value);
    }

    public function test_invalid_signature_is_rejected_and_does_not_touch_session(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $session = WhatsappSession::factory()->forReseller($reseller)->create();

        $body = json_encode(['session_key' => (string) $reseller->id, 'status' => 'connected']);

        $response = $this->call('POST', '/api/v1/whatsapp/webhook/session-status', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Whatsapp-Signature' => 'obviously-wrong-signature',
            'HTTP_X-Whatsapp-Timestamp' => (string) time(),
        ], $body);

        // Always 200 (same posture as XenditWebhookController) — rejection
        // only visible in the result payload, never a non-2xx status.
        $response->assertOk();
        $response->assertJsonPath('data.result', 'rejected');
        $this->assertSame('qr_pending', $session->fresh()->status->value);
    }

    public function test_replayed_signature_outside_tolerance_window_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $session = WhatsappSession::factory()->forReseller($reseller)->create();

        $payload = ['session_key' => (string) $reseller->id, 'status' => 'connected'];
        $body = json_encode($payload);
        $oldTimestamp = time() - 600;
        $signature = (new WhatsappHmac('test-shared-secret'))->sign($body, $oldTimestamp);

        $response = $this->call('POST', '/api/v1/whatsapp/webhook/session-status', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Whatsapp-Signature' => $signature,
            'HTTP_X-Whatsapp-Timestamp' => (string) $oldTimestamp,
        ], $body);

        $response->assertJsonPath('data.result', 'rejected');
        $this->assertSame('qr_pending', $session->fresh()->status->value);
    }
}
