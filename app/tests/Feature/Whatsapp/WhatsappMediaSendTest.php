<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Enums\WhatsappMessageStatus;
use App\Exceptions\WhatsappAttachmentException;
use App\Jobs\SendWhatsappGroupMessageJob;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Tenant;
use App\Models\WhatsappGatewaySettings;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WorkOrder;
use App\Models\WorkOrderPhoto;
use App\Services\Whatsapp\WhatsappGatewayService;
use App\Support\Whatsapp\WhatsappAttachment;
use App\Support\WhatsappHmac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhatsappMediaSendTest extends TestCase
{
    use RefreshDatabase;

    // FF D8 FF = penanda JPEG yang dikenali finfo (libmagic).
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

    private const PDF = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::fake(['*' => Http::response(['success' => true, 'message' => 'Sent'], 200)]);
        config(['services.whatsapp_gateway.url' => 'http://gateway:3000']);
        // Nol jeda rate-limit supaya test tidak benar-benar sleep.
        $s = WhatsappGatewaySettings::current();
        $s->update(['rate_limit_delay_min_seconds' => 0, 'rate_limit_delay_max_seconds' => 0]);
    }

    private function putFile(string $path, string $contents): void
    {
        Storage::disk('local')->put($path, $contents);
    }

    private function mediaLog(array $overrides = []): WhatsappMessageLog
    {
        $tenant = Tenant::factory()->create();

        return WhatsappMessageLog::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'phone_number' => '6287884374939',
            'event_type' => WhatsappEventType::WorkOrderActivationReady,
            'rendered_content' => 'Caption uji',
            'attachment' => [
                'disk' => 'local',
                'path' => 'work-order-photos/9/speedtest.jpg',
                'mime' => 'image/jpeg',
                'filename' => 'speedtest.jpg',
                'caption' => null,
            ],
            'status' => WhatsappMessageStatus::Queued,
            'queued_at' => now(),
        ], $overrides));
    }

    public function test_media_payload_hits_send_media_with_correct_fields_and_hmac(): void
    {
        $this->putFile('work-order-photos/9/speedtest.jpg', self::JPEG);
        $log = $this->mediaLog();

        (new SendWhatsappMessageJob($log->id))->handle(app(WhatsappHmac::class));

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_ends_with($request->url(), '/sessions/direct/send-media')
                && $data['phone_number'] === '6287884374939'
                && $data['caption'] === 'Caption uji'
                && $data['mime'] === 'image/jpeg'
                && $data['filename'] === 'speedtest.jpg'
                && $data['base64'] === base64_encode(self::JPEG)
                && $request->hasHeader('X-Whatsapp-Signature')
                && $request->hasHeader('X-Whatsapp-Timestamp');
        });

        $this->assertSame(WhatsappMessageStatus::Sent, $log->fresh()->status);
    }

    public function test_group_media_hits_send_group_media_endpoint(): void
    {
        $this->putFile('work-order-photos/9/odp_box.jpg', self::JPEG);
        $log = $this->mediaLog([
            'phone_number' => '120363000000000000@g.us',
            'attachment' => ['disk' => 'local', 'path' => 'work-order-photos/9/odp_box.jpg', 'mime' => 'image/jpeg', 'filename' => 'odp_box.jpg', 'caption' => null],
        ]);

        (new SendWhatsappGroupMessageJob($log->id))->handle(app(WhatsappHmac::class));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sessions/direct/send-group-media')
            && $request->data()['group_jid'] === '120363000000000000@g.us');
        $this->assertSame(WhatsappMessageStatus::Sent, $log->fresh()->status);
    }

    public function test_pdf_document_is_sent_with_detected_mime(): void
    {
        $this->putFile('invoice-pdfs/inv-1.pdf', self::PDF);
        $log = $this->mediaLog([
            'attachment' => ['disk' => 'local', 'path' => 'invoice-pdfs/inv-1.pdf', 'mime' => 'application/pdf', 'filename' => 'inv-1.pdf', 'caption' => null],
        ]);

        (new SendWhatsappMessageJob($log->id))->handle(app(WhatsappHmac::class));

        Http::assertSent(fn ($request) => $request->data()['mime'] === 'application/pdf');
    }

    public function test_content_mime_mismatch_is_permanent_failure_and_sends_nothing(): void
    {
        // Isi file teks biasa, tapi attachment mengaku image/jpeg → ditolak.
        $this->putFile('work-order-photos/9/speedtest.jpg', 'ini teks biasa, bukan gambar');
        $log = $this->mediaLog();

        (new SendWhatsappMessageJob($log->id))->handle(app(WhatsappHmac::class));

        Http::assertNothingSent();
        $fresh = $log->fresh();
        $this->assertSame(WhatsappMessageStatus::Failed, $fresh->status);
        $this->assertStringContainsString('MIME', $fresh->failed_reason);
    }

    public function test_over_cap_is_permanent_failure(): void
    {
        config(['services.whatsapp_gateway.media_max_bytes' => 8]);
        $this->putFile('work-order-photos/9/speedtest.jpg', self::JPEG); // > 8 byte
        $log = $this->mediaLog();

        (new SendWhatsappMessageJob($log->id))->handle(app(WhatsappHmac::class));

        Http::assertNothingSent();
        $this->assertSame(WhatsappMessageStatus::Failed, $log->fresh()->status);
    }

    public function test_missing_file_is_permanent_failure(): void
    {
        $log = $this->mediaLog(); // file tidak di-put
        (new SendWhatsappMessageJob($log->id))->handle(app(WhatsappHmac::class));

        Http::assertNothingSent();
        $this->assertSame(WhatsappMessageStatus::Failed, $log->fresh()->status);
    }

    public function test_dto_rejects_path_traversal_absolute_and_outside_prefix(): void
    {
        foreach (['../etc/passwd', '/etc/passwd', 'work-order-photos/../secret.jpg', 'random-folder/x.jpg'] as $bad) {
            $threw = false;
            try {
                new WhatsappAttachment('local', $bad, 'image/jpeg', 'x.jpg');
            } catch (WhatsappAttachmentException) {
                $threw = true;
            }
            $this->assertTrue($threw, "path {$bad} harus ditolak");
        }
    }

    public function test_from_work_order_photo_rejects_other_tenant(): void
    {
        $wo = WorkOrder::factory()->create();
        $photo = WorkOrderPhoto::create([
            'work_order_id' => $wo->id,
            'type' => 'speedtest',
            'file_path' => 'work-order-photos/'.$wo->id.'/speedtest.jpg',
            'uploaded_at' => now(),
        ]);
        $otherTenant = Tenant::factory()->create();

        $this->expectException(WhatsappAttachmentException::class);
        WhatsappAttachment::fromWorkOrderPhoto($photo, $otherTenant->id);
    }

    public function test_from_work_order_photo_accepts_own_tenant(): void
    {
        $wo = WorkOrder::factory()->create();
        $photo = WorkOrderPhoto::create([
            'work_order_id' => $wo->id,
            'type' => 'speedtest',
            'file_path' => 'work-order-photos/'.$wo->id.'/speedtest.jpg',
            'uploaded_at' => now(),
        ]);

        $att = WhatsappAttachment::fromWorkOrderPhoto($photo, $wo->tenant_id, 'cap');
        $this->assertSame('speedtest.jpg', $att->filename);
        $this->assertSame('image/jpeg', $att->mime);
    }

    public function test_six_attachments_create_six_sequential_logs_caption_on_first_only(): void
    {
        $tenant = Tenant::factory()->create();
        WhatsappMessageTemplate::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::WorkOrderActivationReady->value,
            'content' => 'WO selesai {customer_name}',
            'is_active' => true,
        ]);

        $attachments = [];
        for ($i = 1; $i <= 6; $i++) {
            $attachments[] = new WhatsappAttachment('local', "work-order-photos/9/foto{$i}.jpg", 'image/jpeg', "foto{$i}.jpg");
        }

        $logs = app(WhatsappGatewayService::class)->queueMediaBatchForGroup(
            WhatsappEventType::WorkOrderActivationReady,
            $tenant->id,
            '120363000000000000@g.us',
            ['customer_name' => 'Budi'],
            $attachments,
        );

        $this->assertCount(6, $logs);
        $ids = array_map(fn ($l) => $l->id, $logs);
        $this->assertSame($ids, array_values(array_unique($ids)), 'id log berurutan unik');
        $this->assertSame('WO selesai Budi', $logs[0]->rendered_content);
        for ($i = 1; $i < 6; $i++) {
            $this->assertSame('', $logs[$i]->rendered_content, "lampiran ke-{$i} tanpa caption");
        }
        $this->assertSame(6, WhatsappMessageLog::withoutGlobalScopes()->whereNotNull('attachment')->count());
    }

    public function test_retry_does_not_resend_an_already_sent_log(): void
    {
        $this->putFile('work-order-photos/9/speedtest.jpg', self::JPEG);
        $log = $this->mediaLog();

        $job = new SendWhatsappMessageJob($log->id);
        $job->handle(app(WhatsappHmac::class));
        // Panggilan kedua (duplicate pop) — status sudah Sent → no-op.
        $job->handle(app(WhatsappHmac::class));

        Http::assertSentCount(1);
    }

    public function test_base64_never_appears_in_log_fields(): void
    {
        $this->putFile('work-order-photos/9/speedtest.jpg', self::JPEG);
        // Gateway balas 502 → failed_reason terisi; pastikan TIDAK ada base64.
        Http::fake(['*' => Http::response('gateway boom', 502)]);
        $log = $this->mediaLog();

        (new SendWhatsappMessageJob($log->id))->handle(app(WhatsappHmac::class));

        $fresh = $log->fresh();
        $b64 = base64_encode(self::JPEG);
        $this->assertStringNotContainsString($b64, (string) $fresh->failed_reason);
        $this->assertStringNotContainsString($b64, (string) $fresh->rendered_content);
        $this->assertNull($fresh->attachment['base64'] ?? null);
    }
}
