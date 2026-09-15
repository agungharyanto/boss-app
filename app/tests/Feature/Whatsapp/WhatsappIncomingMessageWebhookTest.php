<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Reseller;
use App\Models\Tenant;
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

    // --- reseller_id resolution (perluasan) ---

    public function test_session_key_direct_resolves_to_null_reseller_id(): void
    {
        $response = $this->postSigned($this->validPayload(['session_key' => 'direct']));

        $response->assertJsonPath('data.result', 'recorded');
        $this->assertDatabaseHas('whatsapp_incoming_messages', [
            'session_key' => 'direct',
            'reseller_id' => null,
        ]);
    }

    public function test_session_key_matching_a_real_reseller_resolves_to_its_id(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->postSigned($this->validPayload(['session_key' => (string) $reseller->id]));

        $response->assertJsonPath('data.result', 'recorded');
        $this->assertDatabaseHas('whatsapp_incoming_messages', [
            'session_key' => (string) $reseller->id,
            'reseller_id' => $reseller->id,
        ]);
    }

    /**
     * session_key numerik tapi tidak cocok reseller manapun (mis. reseller
     * sudah dihapus) — TETAP direkam (reseller_id null), webhook TIDAK
     * ditolak. Konsisten "selalu simpan + 200, jangan bikin whatsmeow retry".
     */
    public function test_session_key_with_no_matching_reseller_still_records_with_null_reseller_id(): void
    {
        $response = $this->postSigned($this->validPayload(['session_key' => '999999']));

        $response->assertOk();
        $response->assertJsonPath('data.result', 'recorded');
        $this->assertDatabaseHas('whatsapp_incoming_messages', [
            'session_key' => '999999',
            'reseller_id' => null,
        ]);
    }

    // --- is_lid (fix bug LID, 2026-09-15) ---

    public function test_is_lid_true_is_recorded_when_gateway_sends_it(): void
    {
        $response = $this->postSigned($this->validPayload([
            'sender_phone' => '44435932971043',
            'chat_jid' => '44435932971043@lid',
            'is_lid' => true,
        ]));

        $response->assertJsonPath('data.result', 'recorded');
        $this->assertDatabaseHas('whatsapp_incoming_messages', [
            'sender_phone' => '44435932971043',
            'is_lid' => true,
        ]);
    }

    public function test_is_lid_false_is_recorded_when_gateway_sends_it(): void
    {
        $response = $this->postSigned($this->validPayload(['is_lid' => false]));

        $response->assertJsonPath('data.result', 'recorded');
        $this->assertDatabaseHas('whatsapp_incoming_messages', [
            'message_id' => $this->validPayload()['message_id'],
            'is_lid' => false,
        ]);
    }

    /**
     * Backward-compat — payload lama (sebelum fix bug LID) tidak mengirim
     * field ini sama sekali. validPayload() TIDAK menyertakan is_lid
     * secara default, jadi test ini mereproduksi persis payload lama.
     * Harus tetap direkam (bukan ditolak) dengan default false — konsisten
     * dengan default kolom DB.
     */
    public function test_missing_is_lid_field_defaults_to_false(): void
    {
        $payload = $this->validPayload();
        $this->assertArrayNotHasKey('is_lid', $payload);

        $response = $this->postSigned($payload);

        $response->assertJsonPath('data.result', 'recorded');
        $this->assertDatabaseHas('whatsapp_incoming_messages', [
            'message_id' => $payload['message_id'],
            'is_lid' => false,
        ]);
    }

    // --- retroactive backfill LID (lanjutan fix bug LID, 2026-09-15) ---

    /**
     * Skenario inti: kontak sudah kirim 3 pesan LAMA (is_lid=true, LID
     * sama, chat_jid sama persis "@lid") sebelum PN-nya pernah berhasil
     * di-resolve. Pesan ke-4 dari kontak yang SAMA (chat_jid identik)
     * akhirnya datang dengan PN berhasil di-resolve (is_lid=false) —
     * SEMUA 4 baris (3 lama + 1 baru) harus sender_phone SAMA + is_lid
     * false setelahnya, bukan cuma baris ke-4.
     */
    public function test_resolving_a_lid_contact_retroactively_backfills_all_older_messages_from_the_same_contact(): void
    {
        $lidChatJid = '44435932971043@lid';
        for ($i = 1; $i <= 3; $i++) {
            WhatsappIncomingMessage::create([
                'session_key' => 'direct',
                'reseller_id' => null,
                'sender_phone' => '44435932971043',
                'is_lid' => true,
                'chat_jid' => $lidChatJid,
                'message_id' => "MSG-OLD-{$i}",
                'text' => "Pesan lama ke-{$i}",
                'received_at' => now(),
            ]);
        }

        $response = $this->postSigned($this->validPayload([
            'sender_phone' => '081234567890',
            'chat_jid' => $lidChatJid,
            'is_lid' => false,
            'message_id' => 'MSG-NEW-RESOLVED',
        ]));

        $response->assertJsonPath('data.result', 'recorded');

        foreach (['MSG-OLD-1', 'MSG-OLD-2', 'MSG-OLD-3', 'MSG-NEW-RESOLVED'] as $messageId) {
            $this->assertDatabaseHas('whatsapp_incoming_messages', [
                'message_id' => $messageId,
                'sender_phone' => '081234567890',
                'is_lid' => false,
            ]);
        }
    }

    /**
     * Isolasi ketat — kontak B (LID/chat_jid BEDA) yang juga is_lid=true
     * TIDAK BOLEH ikut ter-update saat kontak A ter-resolve, meski
     * sama-sama masih is_lid=true di waktu yang sama.
     */
    public function test_resolving_one_lid_contact_never_touches_a_different_lid_contact(): void
    {
        WhatsappIncomingMessage::create([
            'session_key' => 'direct',
            'reseller_id' => null,
            'sender_phone' => '221032002642155',
            'is_lid' => true,
            'chat_jid' => '221032002642155@lid', // kontak B — LID BEDA
            'message_id' => 'MSG-CONTACT-B',
            'text' => 'Pesan dari kontak B yang belum resolve',
            'received_at' => now(),
        ]);

        $response = $this->postSigned($this->validPayload([
            'sender_phone' => '081234567890',
            'chat_jid' => '44435932971043@lid', // kontak A — LID lain
            'is_lid' => false,
            'message_id' => 'MSG-CONTACT-A-RESOLVED',
        ]));

        $response->assertJsonPath('data.result', 'recorded');

        // Kontak B TIDAK tersentuh sama sekali — masih raw LID, is_lid=true.
        $this->assertDatabaseHas('whatsapp_incoming_messages', [
            'message_id' => 'MSG-CONTACT-B',
            'sender_phone' => '221032002642155',
            'is_lid' => true,
        ]);
    }

    /**
     * Baris baru yang RESOLUSINYA SENDIRI gagal (masih is_lid=true) tidak
     * pernah memicu backfill apa pun — tidak ada PN baru untuk dibagikan
     * ke baris lama.
     */
    public function test_a_new_message_that_is_still_lid_does_not_trigger_any_backfill(): void
    {
        $lidChatJid = '44435932971043@lid';
        WhatsappIncomingMessage::create([
            'session_key' => 'direct',
            'reseller_id' => null,
            'sender_phone' => '44435932971043',
            'is_lid' => true,
            'chat_jid' => $lidChatJid,
            'message_id' => 'MSG-OLD-STILL-LID',
            'text' => 'Pesan lama',
            'received_at' => now(),
        ]);

        $response = $this->postSigned($this->validPayload([
            'sender_phone' => '44435932971043',
            'chat_jid' => $lidChatJid,
            'is_lid' => true,
            'message_id' => 'MSG-NEW-STILL-LID',
        ]));

        $response->assertJsonPath('data.result', 'recorded');

        // Keduanya tetap raw LID + is_lid=true — tidak ada yang berubah.
        $this->assertDatabaseHas('whatsapp_incoming_messages', [
            'message_id' => 'MSG-OLD-STILL-LID',
            'sender_phone' => '44435932971043',
            'is_lid' => true,
        ]);
        $this->assertDatabaseHas('whatsapp_incoming_messages', [
            'message_id' => 'MSG-NEW-STILL-LID',
            'sender_phone' => '44435932971043',
            'is_lid' => true,
        ]);
    }

    /**
     * Duplikat/retry message_id yang sudah ada (wasRecentlyCreated=false)
     * TIDAK memicu backfill lagi — idempotency guard yang sudah ada
     * (firstOrCreate) sudah cukup, method ini cuma jaring pengaman
     * tambahan supaya replay webhook tidak melakukan UPDATE percuma
     * berulang-ulang (harmless kalau terjadi, tapi tidak seharusnya).
     */
    public function test_replaying_an_already_recorded_resolved_message_does_not_error_or_double_process(): void
    {
        $payload = $this->validPayload([
            'sender_phone' => '081234567890',
            'chat_jid' => '44435932971043@lid',
            'is_lid' => false,
            'message_id' => 'MSG-REPLAY',
        ]);

        $this->postSigned($payload)->assertJsonPath('data.result', 'recorded');
        $this->postSigned($payload)->assertJsonPath('data.result', 'recorded');

        $this->assertSame(1, WhatsappIncomingMessage::where('message_id', 'MSG-REPLAY')->count());
    }
}
