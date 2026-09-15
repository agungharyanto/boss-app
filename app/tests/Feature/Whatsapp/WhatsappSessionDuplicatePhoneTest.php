<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Jobs\SendWhatsappMessageJob;
use App\Livewire\Whatsapp\WhatsappGatewayIndex;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappSession;
use App\Support\ResellerContext;
use App\Support\WhatsappHmac;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 2026-09-15 — cegah 1 nomor WA fisik dipakai di lebih dari 1 session BOSS
 * App sekaligus. Ditemukan lewat verifikasi manual nyata (Agung sempat
 * pairing nomor yang sama ke session "direct" DAN session reseller
 * sekaligus). Pola test PERSIS WhatsappSessionWebhookTest (sama HMAC
 * helper, sama style postSigned()).
 */
class WhatsappSessionDuplicatePhoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp_gateway.hmac_secret' => 'test-shared-secret',
            'services.whatsapp_gateway.url' => 'http://whatsapp-gateway-test',
        ]);

        Http::fake([
            'whatsapp-gateway-test/*' => Http::response(['success' => true], 200),
        ]);
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

    public function test_second_session_pairing_the_same_number_as_an_already_connected_session_is_rejected(): void
    {
        // SendWhatsappMessageJob menerapkan sleep() rate-limit nyata
        // (5-10 detik, lihat CLAUDE.md) sebelum benar-benar mengirim —
        // Queue::fake() supaya test ini cuma memverifikasi dispatch-nya
        // (assertion (c) di bawah), tidak genuinely menjalankan job dan
        // menunggu delay itu.
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Reseller A']);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Reseller B']);
        $sessionA = WhatsappSession::factory()->forReseller($resellerA)->connected()->create(['phone_number' => '6281234567890']);
        $sessionB = WhatsappSession::factory()->forReseller($resellerB)->create();

        WhatsappMessageTemplate::create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::DuplicateSessionAttempt,
            'content' => 'Nomor Anda dicoba dipasang ulang oleh {reseller_name} pada {attempted_at}.',
            'is_active' => true,
        ]);

        $response = $this->postSigned([
            'session_key' => (string) $resellerB->id,
            'status' => 'connected',
            'phone_number' => '6281234567890',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.result', 'applied');

        // (a) Session B TIDAK disimpan sebagai connected.
        $sessionB->refresh();
        $this->assertSame('rejected_duplicate', $sessionB->status->value);
        $this->assertNotNull($sessionB->status_reason);
        $this->assertStringContainsString('sudah digunakan', $sessionB->status_reason);

        // Session A (yang lama) TIDAK tersentuh sama sekali.
        $sessionA->refresh();
        $this->assertSame('connected', $sessionA->status->value);
        $this->assertSame('6281234567890', $sessionA->phone_number);

        // (b) Gateway logout dipanggil untuk session_key milik B, BUKAN A.
        Http::assertSent(function ($request) use ($resellerB) {
            return str_contains($request->url(), "/sessions/{$resellerB->id}/logout");
        });

        // (c) WA notifikasi dikirim LEWAT session A (reseller_id A) —
        // bukan session B yang barusan ditolak.
        $this->assertDatabaseHas('whatsapp_message_logs', [
            'reseller_id' => $resellerA->id,
            'event_type' => WhatsappEventType::DuplicateSessionAttempt->value,
            'phone_number' => '6281234567890',
        ]);
        $log = WhatsappMessageLog::where('event_type', WhatsappEventType::DuplicateSessionAttempt->value)->first();
        $this->assertStringContainsString('Reseller B', $log->rendered_content);

        // Job dispatch ke antrian "whatsapp-{reseller_id A}" — SESI LAMA,
        // bukan sesi B yang barusan ditolak.
        Queue::assertPushedOn('whatsapp-'.$resellerA->id, SendWhatsappMessageJob::class);
    }

    /**
     * Re-pairing nomor yang SAMA ke session yang SAMA (session_key sama,
     * bukan session lain) — BUKAN duplikat, harus tetap diizinkan (mis.
     * reseller logout lalu connect ulang nomor miliknya sendiri).
     */
    public function test_re_pairing_the_same_number_to_the_same_session_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $session = WhatsappSession::factory()->forReseller($reseller)->connected()->create(['phone_number' => '6281234567890']);

        // Disconnect dulu (simulasi logout), lalu connect ULANG dengan
        // nomor yang PERSIS SAMA — session_key TETAP sama (session ini
        // sendiri).
        $session->update(['status' => 'disconnected']);

        $response = $this->postSigned([
            'session_key' => (string) $reseller->id,
            'status' => 'connected',
            'phone_number' => '6281234567890',
        ]);

        $response->assertJsonPath('data.result', 'applied');
        $session->refresh();
        $this->assertSame('connected', $session->status->value);
        $this->assertNull($session->status_reason);
        $this->assertSame('6281234567890', $session->phone_number);
    }

    /**
     * Session yang GENUINELY tidak punya duplikat (nomor beda dari semua
     * session connected lain) tetap berhasil connected seperti biasa —
     * regression check supaya guard baru ini tidak diam-diam menolak
     * kasus normal.
     */
    public function test_a_genuinely_new_phone_number_connects_normally(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        WhatsappSession::factory()->forReseller($resellerA)->connected()->create(['phone_number' => '6281111111111']);
        $sessionB = WhatsappSession::factory()->forReseller($resellerB)->create();

        $response = $this->postSigned([
            'session_key' => (string) $resellerB->id,
            'status' => 'connected',
            'phone_number' => '6282222222222',
        ]);

        $response->assertJsonPath('data.result', 'applied');
        $sessionB->refresh();
        $this->assertSame('connected', $sessionB->status->value);
        $this->assertSame('6282222222222', $sessionB->phone_number);
    }

    /**
     * Bukti langsung constraint DB (partial unique index
     * whatsapp_sessions_connected_phone_unique, migration
     * 2026_09_15_100000) genuinely mencegah 2 baris connected dengan
     * phone_number sama pada level DATABASE ITU SENDIRI — safety net
     * final terhadap race yang mungkin lolos SELECT check di level
     * aplikasi (2 webhook nyaris bersamaan). Dites langsung via query
     * builder (bypass service/applyStatus() sepenuhnya) supaya murni
     * membuktikan constraint-nya, terpisah dari logic aplikasi yang
     * sudah dites di atas.
     */
    public function test_db_unique_constraint_itself_rejects_two_connected_rows_with_the_same_phone_number(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        WhatsappSession::factory()->forReseller($resellerA)->connected()->create(['phone_number' => '6281234567890']);
        $sessionB = WhatsappSession::factory()->forReseller($resellerB)->create();

        $this->expectException(QueryException::class);

        // Raw update, BYPASS applyStatus()/service sepenuhnya — membuktikan
        // constraint DB sendiri yang menolak, bukan cuma logic PHP.
        DB::table('whatsapp_sessions')
            ->where('id', $sessionB->id)
            ->update(['phone_number' => '6281234567890', 'status' => 'connected']);
    }

    /**
     * UI — reseller yang session-nya BARU SAJA ditolak (nomor duplikat)
     * melihat pesan error YANG JELAS di tab Konfigurasi-nya sendiri,
     * bukan status generik tanpa penjelasan.
     */
    public function test_ui_shows_a_clear_error_message_for_a_reseller_whose_pairing_was_rejected_as_duplicate(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        WhatsappSession::factory()->forReseller($resellerA)->connected()->create(['phone_number' => '6281234567890']);
        WhatsappSession::factory()->forReseller($resellerB)->create();

        $ownerB = User::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB->users()->attach($ownerB->id, ['role' => 'owner', 'status' => 'active']);

        $this->postSigned([
            'session_key' => (string) $resellerB->id,
            'status' => 'connected',
            'phone_number' => '6281234567890',
        ])->assertJsonPath('data.result', 'applied');

        app(ResellerContext::class)->set($resellerB);

        Livewire::actingAs($ownerB)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'konfigurasi')
            ->assertSee('Ditolak (Nomor Duplikat)')
            ->assertSee('Nomor ini sudah digunakan di BOSS App dengan sesi yang lain');
    }
}
