<?php

namespace App\Jobs;

use App\Enums\WhatsappMessageStatus;
use App\Models\WhatsappGatewaySettings;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappSession;
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
        // Branch migrasi-whatsmeow — dialihkan ke gateway Go/whatsmeow,
        // konsisten dengan WhatsappSessionService::refreshQrCode()/
        // requestPairingCode()/reconcileFromGateway() (kalau tetap
        // mengarah ke gateway lama, sesi yang di-pairing di Go tidak akan
        // pernah bisa kirim pesan sama sekali — dua gateway punya state
        // sesi yang sepenuhnya independen).
        $baseUrl = config('services.whatsapp_gateway_go.url');

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
            // Sedikit di atas gateway's own SEND_TIMEOUT_MS (20s, lihat
            // whatsapp-gateway/src/sessionManager.js) supaya error nyata
            // dari Baileys ("session unhealthy") yang propagate ke sini,
            // bukan cURL-28 opaque. Solusi robustness, bukan sekadar naikkan
            // angka: gateway sekarang fail-fast, ini cuma beri marginnya.
            ->timeout(35)
            ->post(rtrim((string) $baseUrl, '/')."/sessions/{$sessionKey}/send");
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
