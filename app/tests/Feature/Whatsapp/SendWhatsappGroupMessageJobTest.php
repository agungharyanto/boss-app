<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\WhatsappMessageStatus;
use App\Jobs\SendWhatsappGroupMessageJob;
use App\Models\Tenant;
use App\Models\WhatsappMessageLog;
use App\Support\WhatsappHmac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * v0.26.4 — SendWhatsappGroupMessageJob. Retry/backoff diverifikasi lewat
 * withFakeQueueInteractions() (pola sama PushCustomerIpPoolToMikrotikJob
 * dkk), bukan lewat dispatch/queue asli. `handle()` dipanggil LANGSUNG,
 * jadi TIDAK butuh Bus::fake() — job ini tidak dispatch job lain.
 * `Sleep::fake()` WAJIB — tanpanya, applyRateLimitDelay() genuinely
 * `sleep()` 5-10 detik SETIAP panggilan handle() (6 test x rata-rata 7s =
 * ~40 detik sia-sia untuk file ini saja, dikonfirmasi nyata sebelum fake
 * ini ditambahkan).
 */
class SendWhatsappGroupMessageJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp_gateway.url' => 'http://whatsapp-gateway-test']);

        Sleep::fake();
    }

    private function groupLog(): WhatsappMessageLog
    {
        $tenant = Tenant::factory()->create();

        return WhatsappMessageLog::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'customer_id' => null,
            'phone_number' => '1203aaa@g.us',
            'status' => WhatsappMessageStatus::Queued,
            'attempts' => 0,
        ]);
    }

    public function test_job_marks_the_log_sent_on_gateway_success(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/send-group' => Http::response(['success' => true, 'message' => 'Sent'], 200),
        ]);

        $log = $this->groupLog();

        $job = new SendWhatsappGroupMessageJob($log->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(WhatsappHmac::class));

        $log->refresh();
        $this->assertSame(WhatsappMessageStatus::Sent, $log->status);
        $this->assertNotNull($log->sent_at);
        $job->assertNotReleased();

        Http::assertSent(function ($request) use ($log) {
            return str_contains($request->url(), '/sessions/direct/send-group')
                && $request['group_jid'] === $log->phone_number
                && $request['message'] === $log->rendered_content;
        });
    }

    public function test_job_releases_with_backoff_on_a_non_final_transient_failure(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/send-group' => Http::response(['success' => false, 'message' => 'send timeout after 20s'], 502),
        ]);

        $log = $this->groupLog();

        $job = new SendWhatsappGroupMessageJob($log->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 1;
        $job->handle(app(WhatsappHmac::class));

        $job->assertReleased(delay: 30);
        $log->refresh();
        $this->assertSame(WhatsappMessageStatus::Queued, $log->status);
        $this->assertStringContainsString('send timeout', $log->failed_reason);
    }

    public function test_job_marks_failed_on_the_final_transient_attempt(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/send-group' => Http::response(['success' => false, 'message' => 'send timeout after 20s'], 502),
        ]);

        $log = $this->groupLog();

        $job = new SendWhatsappGroupMessageJob($log->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 3; // === $job->tries, percobaan terakhir.
        $job->handle(app(WhatsappHmac::class));

        $job->assertNotReleased();
        $log->refresh();
        $this->assertSame(WhatsappMessageStatus::Failed, $log->status);
    }

    /**
     * KASUS UTAMA — kegagalan PERMANEN (flag "permanent": true dari
     * gateway, lihat docs/whatsapp-gateway-api-surface.md endpoint 7):
     * LANGSUNG failed di percobaan PERTAMA, TIDAK release() sama sekali
     * — beda dari kegagalan transien di atas yang mendapat backoff 3x.
     */
    public function test_job_does_not_retry_on_a_permanent_not_in_group_failure(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/send-group' => Http::response([
                'success' => false,
                'message' => "failed to get group members: you're not participating in that group",
                'permanent' => true,
            ], 502),
        ]);

        $log = $this->groupLog();

        $job = new SendWhatsappGroupMessageJob($log->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 1; // Percobaan PERTAMA — bukan attempt terakhir.
        $job->handle(app(WhatsappHmac::class));

        // TIDAK di-release meski ini baru percobaan pertama dari 3 —
        // beda kunci dari test_job_releases_with_backoff... di atas.
        $job->assertNotReleased();
        $log->refresh();
        $this->assertSame(WhatsappMessageStatus::Failed, $log->status);
        $this->assertStringContainsString('not participating', $log->failed_reason);
    }

    public function test_a_missing_permanent_flag_still_retries_normally(): void
    {
        // Response gagal TANPA flag "permanent" sama sekali (kegagalan
        // gateway biasa, mis. HTTP 500 murni) — harus retry seperti biasa,
        // bukan diam-diam diperlakukan sebagai permanen.
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/send-group' => Http::response(['success' => false, 'message' => 'internal error'], 500),
        ]);

        $log = $this->groupLog();

        $job = new SendWhatsappGroupMessageJob($log->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 1;
        $job->handle(app(WhatsappHmac::class));

        $job->assertReleased(delay: 30);
        $log->refresh();
        $this->assertSame(WhatsappMessageStatus::Queued, $log->status);
    }

    public function test_a_manual_retry_dispatch_for_an_already_sent_log_is_a_noop(): void
    {
        Http::fake();

        $log = $this->groupLog();
        $log->update(['status' => WhatsappMessageStatus::Sent, 'sent_at' => now()]);

        $job = new SendWhatsappGroupMessageJob($log->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(WhatsappHmac::class));

        $job->assertNotReleased();
        Http::assertNothingSent();
    }
}
