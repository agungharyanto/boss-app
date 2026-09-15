<?php

namespace Tests\Feature\Whatsapp;

use App\Models\WhatsappIncomingMessage;
use App\Support\WhatsappHmac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * v0.13.1 — POST /api/v1/whatsapp/webhook/incoming-message. Pola test PERSIS
 * WhatsappSessionWebhookTest (sama HMAC helper, sama style postSigned()) —
 * lihat file itu untuk endpoint session-status yang jadi acuan.
 */
class WhatsappIncomingMessageWebhookTest extends TestCase
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

        return $this->call('POST', '/api/v1/whatsapp/webhook/incoming-message', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Whatsapp-Signature' => $signature,
            'HTTP_X-Whatsapp-Timestamp' => (string) $timestamp,
        ], $body);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'session_key' => 'direct',
            'sender_phone' => '081234567890',
            'chat_jid' => '6281234567890@s.whatsapp.net',
            'text' => 'Halo, ini pesan test',
            'message_id' => '3EB0ABCDEF1234567890',
            'timestamp' => time(),
            'push_name' => 'Agung Test',
        ], $overrides);
    }

    public function test_valid_signature_records_the_message(): void
    {
        $response = $this->postSigned($this->validPayload());

        $response->assertOk();
        $response->assertJsonPath('data.result', 'recorded');

        $this->assertDatabaseHas('whatsapp_incoming_messages', [
            'session_key' => 'direct',
            // Format lokal 0xxx — dikirim apa adanya oleh sisi Go
            // (jidnorm.ToLocalIndonesian sudah mengonversi sebelum payload
            // dikirim), controller/service tidak melakukan konversi apa pun.
            'sender_phone' => '081234567890',
            'chat_jid' => '6281234567890@s.whatsapp.net',
            'message_id' => '3EB0ABCDEF1234567890',
            'text' => 'Halo, ini pesan test',
            'push_name' => 'Agung Test',
        ]);
    }

    public function test_push_name_is_nullable(): void
    {
        $payload = $this->validPayload();
        unset($payload['push_name']);

        $response = $this->postSigned($payload);

        $response->assertJsonPath('data.result', 'recorded');
        $this->assertDatabaseHas('whatsapp_incoming_messages', [
            'message_id' => $payload['message_id'],
            'push_name' => null,
        ]);
    }

    public function test_duplicate_message_id_is_idempotent_not_a_duplicate_row(): void
    {
        $payload = $this->validPayload();

        $this->postSigned($payload)->assertJsonPath('data.result', 'recorded');
        // Kirim ulang PERSIS payload yang sama — whatsmeow bisa retry/
        // offline-sync mengirim event yang sama lebih dari sekali.
        $this->postSigned($payload)->assertJsonPath('data.result', 'recorded');

        $this->assertSame(1, WhatsappIncomingMessage::where('message_id', $payload['message_id'])->count());
    }

    public function test_invalid_signature_is_rejected_and_nothing_is_stored(): void
    {
        $body = json_encode($this->validPayload());

        $response = $this->call('POST', '/api/v1/whatsapp/webhook/incoming-message', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Whatsapp-Signature' => 'obviously-wrong-signature',
            'HTTP_X-Whatsapp-Timestamp' => (string) time(),
        ], $body);

        // Always 200 (same posture as session-status/Xendit) — rejection
        // only visible in the result payload, never a non-2xx status.
        $response->assertOk();
        $response->assertJsonPath('data.result', 'rejected');
        $this->assertDatabaseCount('whatsapp_incoming_messages', 0);
    }

    public function test_replayed_signature_outside_tolerance_window_is_rejected(): void
    {
        $payload = $this->validPayload();
        $body = json_encode($payload);
        $oldTimestamp = time() - 600;
        $signature = (new WhatsappHmac('test-shared-secret'))->sign($body, $oldTimestamp);

        $response = $this->call('POST', '/api/v1/whatsapp/webhook/incoming-message', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Whatsapp-Signature' => $signature,
            'HTTP_X-Whatsapp-Timestamp' => (string) $oldTimestamp,
        ], $body);

        $response->assertJsonPath('data.result', 'rejected');
        $this->assertDatabaseCount('whatsapp_incoming_messages', 0);
    }

    public function test_missing_required_field_is_rejected(): void
    {
        $payload = $this->validPayload();
        unset($payload['text']);

        $response = $this->postSigned($payload);

        $response->assertJsonPath('data.result', 'rejected');
        $this->assertDatabaseCount('whatsapp_incoming_messages', 0);
    }
}
