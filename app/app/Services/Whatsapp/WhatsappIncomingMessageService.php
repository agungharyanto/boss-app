<?php

namespace App\Services\Whatsapp;

use App\Models\Reseller;
use App\Models\WhatsappIncomingMessage;
use App\Support\WhatsappHmac;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * v0.13.1 — listener pesan masuk WhatsApp, service TERPISAH dari
 * WhatsappSessionService (yang urusannya murni status sesi, bukan konten
 * pesan) — konsisten pola "satu service per concern" yang sudah ada di
 * namespace ini (WhatsappSessionService/WhatsappTemplateService/
 * WhatsappGatewayService).
 *
 * TIDAK ADA state machine/routing/business logic di sini — murni simpan
 * mentah + idempotency guard. Keputusan apa yang dilakukan dengan isi pesan
 * ini (v0.13.2+) sama sekali bukan urusan service ini.
 */
class WhatsappIncomingMessageService
{
    public function __construct(
        private readonly WhatsappHmac $hmac,
    ) {}

    /**
     * POST /api/v1/whatsapp/webhook/incoming-message handler logic. Signature
     * diverifikasi SEBELUM payload disentuh sama sekali — pola PERSIS
     * WhatsappSessionService::updateStatusFromWebhook().
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordFromWebhook(string $rawBody, ?string $signature, ?string $timestampHeader, array $payload): bool
    {
        if ($signature === null || $timestampHeader === null || ! ctype_digit($timestampHeader)
            || ! $this->hmac->verify($rawBody, $signature, (int) $timestampHeader)) {
            Log::warning('WhatsappIncomingMessageService: rejected webhook with invalid/missing HMAC signature.');

            return false;
        }

        $sessionKey = $payload['session_key'] ?? null;
        $senderPhone = $payload['sender_phone'] ?? null;
        $chatJid = $payload['chat_jid'] ?? null;
        $messageId = $payload['message_id'] ?? null;
        $text = $payload['text'] ?? null;
        $timestamp = $payload['timestamp'] ?? null;

        if (! is_string($sessionKey) || $sessionKey === ''
            || ! is_string($senderPhone) || $senderPhone === ''
            || ! is_string($chatJid) || $chatJid === ''
            || ! is_string($messageId) || $messageId === ''
            || ! is_string($text) || $text === ''
            || ! is_int($timestamp)) {
            Log::warning('WhatsappIncomingMessageService: webhook payload missing required fields.');

            return false;
        }

        // firstOrCreate by message_id — idempotency guard (whatsmeow bisa
        // mengirim ulang event yang sama lewat retry/offline-sync di sisi
        // Go). Sebuah baris yang SUDAH ada tidak di-update ulang — message_id
        // yang sama secara definisi membawa isi yang sama, tidak ada yang
        // perlu direfresh.
        WhatsappIncomingMessage::firstOrCreate(
            ['message_id' => $messageId],
            [
                'session_key' => $sessionKey,
                'reseller_id' => $this->resolveResellerId($sessionKey),
                'sender_phone' => $senderPhone,
                'chat_jid' => $chatJid,
                'text' => $text,
                'push_name' => $payload['push_name'] ?? null,
                'received_at' => Carbon::createFromTimestamp($timestamp),
            ]
        );

        return true;
    }

    /**
     * `session_key` -> `reseller_id`, perluasan scoping reseller. `session_key`
     * TIDAK PERNAH dikirim eksplisit dari sisi Go — nilainya SELALU
     * `(string) reseller_id` atau literal 'direct' (dikonfirmasi via
     * App\Models\WhatsappSession::sessionKeyFor(), satu-satunya sumber
     * session_key yang pernah ada). 'direct' -> null. Numerik yang TERNYATA
     * tidak cocok reseller manapun (reseller sudah dihapus, atau data yang
     * genuinely rusak) -> log warning + tetap null, TIDAK menolak webhook —
     * konsisten prinsip "selalu simpan + respons 200, jangan bikin whatsmeow
     * retry" yang sudah berlaku di seluruh modul ini.
     */
    private function resolveResellerId(string $sessionKey): ?int
    {
        if ($sessionKey === 'direct') {
            return null;
        }

        if (! ctype_digit($sessionKey)) {
            Log::warning("WhatsappIncomingMessageService: session_key '{$sessionKey}' bukan 'direct' maupun numerik — reseller_id disimpan null.");

            return null;
        }

        $reseller = Reseller::find((int) $sessionKey);

        if ($reseller === null) {
            Log::warning("WhatsappIncomingMessageService: session_key {$sessionKey} tidak cocok reseller manapun yang masih ada — reseller_id disimpan null.");

            return null;
        }

        return $reseller->id;
    }
}
