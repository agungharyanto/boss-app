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
use Illuminate\Support\Sleep;
use Throwable;

/**
 * v0.26.4 — kembaran `SendWhatsappMessageJob`, TAPI kirim ke GRUP (POST ke
 * `/sessions/{key}/send-group`, bukan `/send`). `$logId` menunjuk baris
 * `whatsapp_message_logs` yang `phone_number`-nya berisi JID grup mentah
 * (Opsi A, lihat docblock `WhatsappMessageLog` model). Dispatched onto
 * queue `whatsapp-{session_key}` PERSIS sama seperti job individu — satu
 * antrean per sesi WA, rate-limit delay tetap berlaku sama, tidak ada
 * jalur bypass khusus grup.
 *
 * Beda satu-satunya yang genuinely material dari job individu: kegagalan
 * PERMANEN (bot sudah bukan anggota grup itu lagi, ditandai flag
 * `permanent: true` di respons Go — lihat
 * `docs/whatsapp-gateway-api-surface.md` endpoint 7, dan
 * `session.IsPermanentGroupSendError()` di sisi Go, dikonfirmasi lewat
 * pembacaan langsung source whatsmeow, bukan ditebak) LANGSUNG ditandai
 * `failed` tanpa `release()` — retry 3x untuk kelas kegagalan ini sia-sia,
 * grup yang bot-nya sudah dikeluarkan tidak akan "kembali" sendiri dalam
 * 30 detik/2 menit/5 menit ke depan.
 */
class SendWhatsappGroupMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $logId) {}

    public function handle(WhatsappHmac $hmac): void
    {
        $log = WhatsappMessageLog::withoutGlobalScopes()->find($this->logId);

        if ($log === null) {
            Log::warning("SendWhatsappGroupMessageJob: WhatsappMessageLog #{$this->logId} not found, skipping.");

            return;
        }

        // Pola sama persis SendWhatsappMessageJob — retry manual/duplicate
        // pop tidak boleh mengirim ulang atau menghitung attempt dobel.
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

            $reason = "HTTP {$response->status()}: {$response->body()}";

            if ($response->json('permanent') === true) {
                $this->recordPermanentFailure($log, $reason);

                return;
            }

            $this->recordFailure($log, $reason);
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

        $body = json_encode([
            // phone_number berisi JID grup mentah untuk baris jenis ini —
            // lihat docblock WhatsappMessageLog model. Field HTTP tujuan
            // adalah group_jid (kontrak endpoint /send-group), bukan
            // phone_number — namanya sengaja beda dari nama kolom DB.
            'group_jid' => $log->phone_number,
            'message' => $log->rendered_content,
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = $hmac->sign($body, $timestamp);

        return Http::withBody($body, 'application/json')
            ->withHeaders([
                'X-Whatsapp-Timestamp' => (string) $timestamp,
                'X-Whatsapp-Signature' => $signature,
            ])
            // Sama margin persis SendWhatsappMessageJob — sedikit di atas
            // gateway's own sendTimeout (20 detik).
            ->timeout(35)
            ->post(rtrim((string) $baseUrl, '/')."/sessions/{$sessionKey}/send-group");
    }

    /**
     * Non-final attempt: release back onto its own queue with an
     * exponential delay (30s / 2min / 5min) and leave status=queued for the
     * next try. Final attempt: mark failed, no further release. Identik
     * SendWhatsappMessageJob — dipertahankan sama persis supaya perilaku
     * retry tidak drift antara dua jalur.
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

    /**
     * v0.26.4 — kegagalan PERMANEN (flag "permanent": true dari gateway,
     * lihat docblock class ini). Langsung failed di percobaan APA PUN —
     * tidak pernah release(), berapa pun sisa attempts() yang ada. Retry
     * tidak akan pernah membuat grup itu "punya bot lagi" sendiri.
     */
    private function recordPermanentFailure(WhatsappMessageLog $log, string $reason): void
    {
        $log->update([
            'status' => WhatsappMessageStatus::Failed,
            'failed_reason' => $reason,
        ]);
    }

    /**
     * `Sleep::for()` (bukan `sleep()` PHP polos) — SATU-SATUNYA beda
     * disengaja dari `SendWhatsappMessageJob` (job individu, tidak disentuh
     * di sub-versi ini): job ini dites lewat pemanggilan `handle()`
     * LANGSUNG (bukan dispatch()+Bus::fake(), lihat
     * SendWhatsappGroupMessageJobTest — retry behavior perlu diverifikasi
     * nyata, tidak bisa lewat Bus::fake() yang justru mencegah handle()
     * pernah jalan) — `Sleep::fake()` membuat delay ini genuinely
     * ter-skip di test tanpa perlu sleep() sungguhan 5-10 detik x 6 test.
     */
    private function applyRateLimitDelay(): void
    {
        $settings = WhatsappGatewaySettings::current();

        $delay = random_int(
            $settings->rate_limit_delay_min_seconds,
            $settings->rate_limit_delay_max_seconds,
        );

        Sleep::for($delay)->seconds();
    }
}
