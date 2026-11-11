<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsappIncomingMessage;
use App\Models\WhatsappSession;
use App\Services\Whatsapp\WhatsappIncomingMediaService;
use App\Support\Filesystem\WebOwnership;
use App\Support\WhatsappHmac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * v0.13.6 — terima media masuk: ingest dari volume gateway → disk privat,
 * validasi finfo/cap/EXIF, serve ber-auth, reconcile sidecar, prune retensi.
 */
class WhatsappIncomingMediaTest extends TestCase
{
    use RefreshDatabase;

    private string $volumeDir;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp_gateway.hmac_secret' => 'test-shared-secret']);

        $this->volumeDir = sys_get_temp_dir().'/wa-incoming-test-'.uniqid();
        File::ensureDirectoryExists($this->volumeDir);
        config(['services.whatsapp_gateway.incoming_media_dir' => $this->volumeDir]);

        Storage::fake('local'); // disk privat boss-app
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->volumeDir);
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────

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

    private function realJpeg(): string
    {
        $img = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($img);
        $out = ob_get_clean();
        imagedestroy($img);

        return $out;
    }

    /** Tulis file + sidecar ke volume gateway seperti yang gateway lakukan. */
    private function putOnVolume(string $sessionKey, string $messageId, string $ext, string $bytes): string
    {
        $dir = $this->volumeDir.'/'.$sessionKey;
        File::ensureDirectoryExists($dir);
        $rel = $sessionKey.'/'.$messageId.'.'.$ext;
        File::put($this->volumeDir.'/'.$rel, $bytes);

        return $rel;
    }

    private function mediaPayload(array $overrides = []): array
    {
        return array_merge([
            'session_key' => 'direct',
            'sender_phone' => '081234567890',
            'chat_jid' => '6281234567890@s.whatsapp.net',
            'text' => '',
            'message_id' => 'MID-'.uniqid(),
            'timestamp' => time(),
            'push_name' => 'Agung',
            'media_type' => 'image',
            'mime' => 'image/jpeg',
            'file_name' => '',
            'file_size' => 100,
            'caption' => 'foto rumah',
            'sha256' => str_repeat('a', 64),
        ], $overrides);
    }

    // ── webhook media ────────────────────────────────────────────────────

    public function test_image_media_webhook_ingests_and_stores_privately(): void
    {
        $jpeg = $this->realJpeg();
        $payload = $this->mediaPayload(['message_id' => 'IMG1']);
        $payload['media_path'] = $this->putOnVolume('direct', 'IMG1', 'jpg', $jpeg);

        $this->postSigned($payload)->assertOk()->assertJsonPath('data.result', 'recorded');

        $msg = WhatsappIncomingMessage::where('message_id', 'IMG1')->first();
        $this->assertNotNull($msg);
        $this->assertSame('image', $msg->media_type);
        $this->assertSame('foto rumah', $msg->media_caption);
        $this->assertNull($msg->media_rejected_reason);
        $this->assertNotNull($msg->media_file_path);
        $this->assertStringStartsWith('whatsapp-incoming/direct/IMG1.', $msg->media_file_path);
        Storage::disk('local')->assertExists($msg->media_file_path);
        // EXIF strip = re-encode: ukuran tersimpan boleh beda, tapi tetap JPEG valid.
        $stored = Storage::disk('local')->get($msg->media_file_path);
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($stored));
    }

    public function test_ingest_invokes_web_ownership_normalize_after_writing(): void
    {
        // Seam: spy helper lewat container — tak bergantung uid proses test.
        // Jalur reconcile (scheduler) jalan sebagai ROOT; helper menormalkan
        // file+direktori baru ke www-data. (Jalur root tak dapat dibuktikan
        // penuh oleh PHPUnit: proses test non-root -> helper no-op; lihat
        // CLAUDE.md v0.13.5.3.1.)
        $spy = Mockery::spy(WebOwnership::class);
        $this->app->instance(WebOwnership::class, $spy);

        $source = $this->volumeDir.'/'.$this->putOnVolume('direct', 'MID-OWN', 'jpg', $this->realJpeg());

        $result = app(WhatsappIncomingMediaService::class)
            ->ingestFromSource('image', 'image/jpeg', $source, 'direct', 'MID-OWN');

        $this->assertArrayHasKey('stored_path', $result);
        Storage::disk('local')->assertExists($result['stored_path']);
        $spy->shouldHaveReceived('normalize')->once()->with(
            Mockery::pattern('#whatsapp-incoming/direct/MID-OWN\\.jpg$#'),
            Mockery::type('string'),
        );
    }

    public function test_svg_disguised_as_document_is_rejected_no_file_stored(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $payload = $this->mediaPayload([
            'message_id' => 'SVG1', 'media_type' => 'document', 'mime' => 'application/pdf',
        ]);
        $payload['media_path'] = $this->putOnVolume('direct', 'SVG1', 'pdf', $svg);

        $this->postSigned($payload)->assertOk();

        $msg = WhatsappIncomingMessage::where('message_id', 'SVG1')->first();
        $this->assertNotNull($msg->media_rejected_reason);
        $this->assertNull($msg->media_file_path);
    }

    public function test_oversized_media_is_rejected(): void
    {
        // image cap 10MB; buat 11MB byte acak (bukan gambar valid juga).
        $big = str_repeat('x', 11 * 1024 * 1024);
        $payload = $this->mediaPayload(['message_id' => 'BIG1']);
        $payload['media_path'] = $this->putOnVolume('direct', 'BIG1', 'jpg', $big);

        $this->postSigned($payload)->assertOk();

        $msg = WhatsappIncomingMessage::where('message_id', 'BIG1')->first();
        $this->assertNotNull($msg->media_rejected_reason);
        $this->assertNull($msg->media_file_path);
    }

    public function test_path_traversal_media_path_is_rejected(): void
    {
        $payload = $this->mediaPayload(['message_id' => 'TRAV1']);
        $payload['media_path'] = '../../../etc/passwd';

        $this->postSigned($payload)->assertOk();

        $msg = WhatsappIncomingMessage::where('message_id', 'TRAV1')->first();
        $this->assertSame('media_path tidak aman', $msg->media_rejected_reason);
        $this->assertNull($msg->media_file_path);
    }

    public function test_media_links_customer_when_phone_matches(): void
    {
        $session = WhatsappSession::factory()->create(['reseller_id' => null]);
        $customer = Customer::factory()->create([
            'tenant_id' => $session->tenant_id,
            'reseller_id' => null,
            'phone_number' => '081234567890',
        ]);
        $jpeg = $this->realJpeg();
        $payload = $this->mediaPayload(['message_id' => 'LINK1']);
        $payload['media_path'] = $this->putOnVolume('direct', 'LINK1', 'jpg', $jpeg);

        $this->postSigned($payload)->assertOk();

        $msg = WhatsappIncomingMessage::where('message_id', 'LINK1')->first();
        $this->assertSame($customer->id, $msg->customer_id);
    }

    public function test_text_message_still_works_without_media(): void
    {
        $payload = [
            'session_key' => 'direct', 'sender_phone' => '081234567890',
            'chat_jid' => '6281234567890@s.whatsapp.net', 'text' => 'halo',
            'message_id' => 'TXT1', 'timestamp' => time(),
        ];
        $this->postSigned($payload)->assertOk();
        $msg = WhatsappIncomingMessage::where('message_id', 'TXT1')->first();
        $this->assertSame('halo', $msg->text);
        $this->assertNull($msg->media_type);
    }

    // ── serve route ──────────────────────────────────────────────────────

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'whatsapp_gateway.view', 'guard_name' => 'web']));

        return $user;
    }

    public function test_serve_route_returns_file_for_authorized_user(): void
    {
        $msg = WhatsappIncomingMessage::create([
            'session_key' => 'direct', 'sender_phone' => '081234567890',
            'chat_jid' => 'x@s.whatsapp.net', 'message_id' => 'S1', 'text' => '',
            'received_at' => now(), 'media_type' => 'image', 'media_mime' => 'image/jpeg',
            'media_file_path' => 'whatsapp-incoming/direct/S1.jpg',
        ]);
        Storage::disk('local')->put('whatsapp-incoming/direct/S1.jpg', $this->realJpeg());

        $this->actingAs($this->admin())
            ->get(route('web.whatsapp-incoming.media', $msg))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_serve_route_forbidden_for_unauthorized_user(): void
    {
        $msg = WhatsappIncomingMessage::create([
            'session_key' => 'direct', 'sender_phone' => '081', 'chat_jid' => 'x',
            'message_id' => 'S2', 'text' => '', 'received_at' => now(),
            'media_type' => 'image', 'media_file_path' => 'whatsapp-incoming/direct/S2.jpg',
        ]);
        $plain = User::factory()->create();

        $this->actingAs($plain)->get(route('web.whatsapp-incoming.media', $msg))->assertForbidden();
    }

    public function test_serve_route_404_when_no_media(): void
    {
        $msg = WhatsappIncomingMessage::create([
            'session_key' => 'direct', 'sender_phone' => '081', 'chat_jid' => 'x',
            'message_id' => 'S3', 'text' => 'teks', 'received_at' => now(),
        ]);

        $this->actingAs($this->admin())->get(route('web.whatsapp-incoming.media', $msg))->assertNotFound();
    }

    // ── reconcile + prune ────────────────────────────────────────────────

    public function test_reconcile_inserts_sidecar_not_yet_in_db_idempotently(): void
    {
        $jpeg = $this->realJpeg();
        $this->putOnVolume('direct', 'REC1', 'jpg', $jpeg);
        // sidecar JSON seperti yang gateway tulis
        File::put($this->volumeDir.'/direct/REC1.json', json_encode([
            'message_id' => 'REC1', 'session_key' => 'direct', 'sender_phone' => '081234567890',
            'is_lid' => false, 'chat_jid' => 'x@s.whatsapp.net', 'media_type' => 'image',
            'mime' => 'image/jpeg', 'file_name' => '', 'file_size' => strlen($jpeg),
            'caption' => 'c', 'sha256' => str_repeat('b', 64), 'timestamp' => time(),
            'push_name' => 'A', 'media_path' => 'direct/REC1.jpg',
        ]));

        $this->artisan('whatsapp:reconcile-incoming-media')->assertSuccessful();
        $this->assertDatabaseHas('whatsapp_incoming_messages', ['message_id' => 'REC1', 'media_type' => 'image']);
        $msg = WhatsappIncomingMessage::where('message_id', 'REC1')->first();
        $this->assertNotNull($msg->media_file_path);

        // idempoten: run lagi tidak menambah baris
        $this->artisan('whatsapp:reconcile-incoming-media')->assertSuccessful();
        $this->assertSame(1, WhatsappIncomingMessage::where('message_id', 'REC1')->count());
    }

    public function test_prune_deletes_old_media_file_keeps_row(): void
    {
        Storage::disk('local')->put('whatsapp-incoming/direct/OLD1.jpg', $this->realJpeg());
        $msg = WhatsappIncomingMessage::create([
            'session_key' => 'direct', 'sender_phone' => '081', 'chat_jid' => 'x',
            'message_id' => 'OLD1', 'text' => '', 'received_at' => now()->subDays(100),
            'media_type' => 'image', 'media_file_path' => 'whatsapp-incoming/direct/OLD1.jpg',
        ]);

        $this->artisan('whatsapp:prune-incoming-media')->assertSuccessful();

        Storage::disk('local')->assertMissing('whatsapp-incoming/direct/OLD1.jpg');
        $msg->refresh();
        $this->assertNull($msg->media_file_path);
        $this->assertDatabaseHas('whatsapp_incoming_messages', ['message_id' => 'OLD1']); // baris tetap
    }
}
