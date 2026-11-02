<?php

namespace App\Jobs;

use App\Enums\WhatsappMessageStatus;
use App\Exceptions\WhatsappAttachmentException;
use App\Models\WhatsappGatewaySettings;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappSession;
use App\Support\Whatsapp\WhatsappAttachment;
use App\Support\WhatsappHmac;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatched onto queue whatsapp-{session_key} (never the default queue —
 * see App\Console\Commands\WhatsappQueueNames for how boss-whatsapp-worker
 * discovers these dynamic queue names). Applies the global rate-limit delay
 * itself (spec: random 5-10s between messages) rather than relying on any
 * queue-level throttle middleware, since the delay must happen even for the
 * very first message in an otherwise-empty queue.
 */
class SendWhatsappMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // v0.13.5.1 — timeout job dinaikkan untuk jalur media (Upload ke server
    // WhatsApp lebih lama dari teks). Teks selesai jauh di bawah ini. HARUS
    // < worker --timeout DAN < queue retry_after (lihat catatan rilis di
    // CLAUDE.md: worker --timeout=120 + REDIS_QUEUE_RETRY_AFTER=150 pada
    // jendela rebuild) agar job tidak diproses ganda.
    public int $timeout = 115;

    public function __construct(public readonly int $logId) {}

    public function handle(WhatsappHmac $hmac): void
    {
        $log = WhatsappMessageLog::withoutGlobalScopes()->find($this->logId);

        if ($log === null) {
            Log::warning("SendWhatsappMessageJob: WhatsappMessageLog #{$this->logId} not found, skipping.");

            return;
        }

        // A manual "Retry" button re-dispatch, or a duplicate pop of an
        // already-completed job — don't resend or double-count attempts.
        if ($log->status !== WhatsappMessageStatus::Queued) {
            return;
        }

        $this->applyRateLimitDelay();

        $log->increment('attempts');

        try {
            $response = $this->sendToGateway($hmac, $log);

            if ($response->successful()) {
                $log->update(['status' => WhatsappMessageStatus::Sent, 'sent_at' => now()]);

                return;
            }

            $this->recordFailure($log, "HTTP {$response->status()}: {$response->body()}");
        } catch (WhatsappAttachmentException $e) {
            // Lampiran tidak valid (file hilang/MIME salah/ukuran) = gagal
            // PERMANEN — retry tidak akan membuat file muncul. Tidak release.
            $log->update([
                'status' => WhatsappMessageStatus::Failed,
                'failed_reason' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->recordFailure($log, $e->getMessage());
        }
    }

    /**
     * Guaranteed final state even if something throws before/outside the
     * handle() try/catch (e.g. a serialization bug) exhausts all retries.
     */
    public function failed(?Throwable $exception): void
    {
        $log = WhatsappMessageLog::withoutGlobalScopes()->find($this->logId);

        if ($log !== null && $log->status !== WhatsappMessageStatus::Sent) {
            $log->update([
                'status' => WhatsappMessageStatus::Failed,
                'failed_reason' => $exception?->getMessage() ?? 'Unknown failure',
            ]);
        }
    }

    private function sendToGateway(WhatsappHmac $hmac, WhatsappMessageLog $log)
    {
        $sessionKey = WhatsappSession::sessionKeyFor($log->reseller_id);
        $baseUrl = config('services.whatsapp_gateway.url');

        if ($log->attachment !== null) {
            return $this->sendMediaToGateway($hmac, $log, $sessionKey, (string) $baseUrl);
        }

        $body = json_encode([
            'phone_number' => $log->phone_number,
            'message' => $log->rendered_content,
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = $hmac->sign($body, $timestamp);

        return Http::withBody($body, 'application/json')
            ->withHeaders([
                'X-Whatsapp-Timestamp' => (string) $timestamp,
                'X-Whatsapp-Signature' => $signature,
            ])
            // Sedikit di atas gateway's own sendTimeout (20 detik, lihat
            // whatsapp-gateway/internal/session/manager.go) supaya error
            // nyata dari gateway ("send timeout after ...") yang propagate
            // ke sini, bukan cURL-28 opaque. Solusi robustness, bukan
            // sekadar naikkan angka: gateway sudah fail-fast, ini cuma
            // beri marginnya.
            ->timeout(35)
            ->post(rtrim((string) $baseUrl, '/')."/sessions/{$sessionKey}/send");
    }

    /**
     * v0.13.5.1 — kirim media (1 lampiran). Re-validasi saat kirim (file ada,
     * MIME dari ISI file via finfo, ukuran <= cap) — gagal = throw
     * WhatsappAttachmentException (ditangani permanen di handle()). base64
     * TIDAK pernah masuk log. Timeout HTTP media lebih panjang dari teks.
     */
    private function sendMediaToGateway(WhatsappHmac $hmac, WhatsappMessageLog $log, string $sessionKey, string $baseUrl)
    {
        $attachment = WhatsappAttachment::fromArray((array) $log->attachment);
        $mime = $attachment->assertValidForSend(); // throws kalau tidak valid

        $body = json_encode([
            'phone_number' => $log->phone_number,
            'caption' => $log->rendered_content,
            'mime' => $mime,
            'filename' => $attachment->filename,
            'base64' => $attachment->base64(),
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = $hmac->sign($body, $timestamp);

        return Http::withBody($body, 'application/json')
            ->withHeaders([
                'X-Whatsapp-Timestamp' => (string) $timestamp,
                'X-Whatsapp-Signature' => $signature,
            ])
            ->timeout((int) config('services.whatsapp_gateway.media_http_timeout', 100))
            ->post(rtrim($baseUrl, '/')."/sessions/{$sessionKey}/send-media");
    }

    /**
     * Non-final attempt: release back onto its own queue with an
     * exponential delay (30s / 2min / 5min) and leave status=queued for the
     * next try. Final attempt: mark failed, no further release.
     */
    private function recordFailure(WhatsappMessageLog $log, string $reason): void
    {
        $isFinalAttempt = $this->attempts() >= $this->tries;

        $log->update([
            'status' => $isFinalAttempt ? WhatsappMessageStatus::Failed : WhatsappMessageStatus::Queued,
            'failed_reason' => $reason,
        ]);

        if (! $isFinalAttempt) {
            $delaySeconds = match ($this->attempts()) {
                1 => 30,
                2 => 120,
                default => 300,
            };

            $this->release($delaySeconds);
        }
    }

    private function applyRateLimitDelay(): void
    {
        $settings = WhatsappGatewaySettings::current();

        $delay = random_int(
            $settings->rate_limit_delay_min_seconds,
            $settings->rate_limit_delay_max_seconds,
        );

        sleep($delay);
    }
}
