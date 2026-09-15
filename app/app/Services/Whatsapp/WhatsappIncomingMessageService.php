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
        // is_lid — opsional untuk backward-compat (payload lama sebelum
        // fix bug LID tidak mengirim field ini sama sekali), default false
        // konsisten dengan default kolom DB.
        $isLid = (bool) ($payload['is_lid'] ?? false);

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
        $message = WhatsappIncomingMessage::firstOrCreate(
            ['message_id' => $messageId],
            [
                'session_key' => $sessionKey,
                'reseller_id' => $this->resolveResellerId($sessionKey),
                'sender_phone' => $senderPhone,
                'is_lid' => $isLid,
                'chat_jid' => $chatJid,
                'text' => $text,
                'push_name' => $payload['push_name'] ?? null,
                'received_at' => Carbon::createFromTimestamp($timestamp),
            ]
        );

        // Retroactive backfill LID (fix bug LID, lanjutan 2026-09-15) —
        // HANYA saat baris ini GENUINELY baru dibuat (bukan re-post
        // duplikat message_id yang sudah ada — wasRecentlyCreated) DAN
        // resolusinya berhasil (bukan is_lid lagi). Baris duplikat/masih
        // is_lid tidak punya PN baru apa pun untuk di-backfill-kan.
        if ($message->wasRecentlyCreated && ! $isLid) {
            $this->backfillResolvedLid($message);
        }

        return true;
    }

    /**
     * Begitu PN sebuah kontak LID berhasil di-resolve (baris BARU
     * is_lid=false), UPDATE semua baris LAMA dari kontak yang SAMA yang
     * masih is_lid=true — sender_phone-nya ikut terisi PN yang baru
     * ketahuan itu, is_lid jadi false juga. Query UPDATE sederhana
     * inline, BUKAN job/queue — dampak performa kecil (cuma jalan saat
     * kontak LID baru pertama kali ke-resolve, jarang terjadi).
     *
     * Identifier "kontak yang sama": `chat_jid` — dikonfirmasi CUKUP
     * STABIL untuk skenario ini via investigasi source whatsmeow
     * (message.go::parseMessageSource(), whatsapp-gateway module cache)
     * sebelum menulis method ini, BUKAN diasumsikan:
     * - Untuk chat 1-on-1 (satu-satunya yang pernah ditangkap
     *   onIncomingMessage()), `Chat = Sender.ToNonAD()` — Chat MENGIKUTI
     *   addressing mode Sender pesan itu sendiri.
     * - Baris LAMA yang is_lid=true SELALU chat_jid berakhiran "@lid"
     *   (cabang "e" resolveSenderPhone di sisi Go hanya tercapai kalau
     *   Sender.Server genuinely LID) — dan LID itu sendiri adalah ID
     *   PERMANEN per akun WhatsApp pengirim (bukan berubah acak antar
     *   pesan), dikonfirmasi juga dari data nyata (baris berturut dari
     *   kontak yang sama SELALU chat_jid identik persis selama masih
     *   LID-addressed).
     * - Filter `is_lid = true` di WHERE clause di bawah OTOMATIS
     *   membatasi matching hanya ke baris yang genuinely LID-addressed
     *   dengan LID SAMA PERSIS — isolasi ketat antar kontak berbeda
     *   didapat gratis dari kombinasi `chat_jid` + `is_lid=true` ini,
     *   tidak perlu kolom identifier terpisah.
     *
     * KETERBATASAN DIKETAHUI, bukan bug — celah struktural yang TIDAK
     * ditutup di sini (di luar scope yang diminta): kalau WhatsApp server
     * MEMIGRASIKAN kontak ini sepenuhnya dari LID-addressed ke
     * PN-addressed (proses nyata, lihat `store.Device.
     * LIDMigrationTimestamp`/`Client::storeLIDSyncMessage()` di
     * whatsmeow — WhatsApp mengirim payload migrasi PN<->LID ke client
     * dari waktu ke waktu, di luar kendali kita), pesan berikutnya dari
     * kontak itu datang dengan `Sender.Server` BUKAN LID lagi sama
     * sekali — `chat_jid`-nya jadi "<PN>@s.whatsapp.net", BEDA dari
     * chat_jid lama, sehingga backfill ini TIDAK terpicu untuk skenario
     * itu (baris lama tetap is_lid=true). Menutupnya butuh reverse-lookup
     * PN->LID (whatsmeow punya `LIDStore::GetLIDForPN()` di sisi Go)
     * yang tidak diminta scope ini — dicatat sebagai jejak, bukan
     * dikerjakan diam-diam.
     */
    private function backfillResolvedLid(WhatsappIncomingMessage $resolvedMessage): void
    {
        WhatsappIncomingMessage::query()
            ->where('chat_jid', $resolvedMessage->chat_jid)
            ->where('is_lid', true)
            ->where('id', '!=', $resolvedMessage->id)
            ->update([
                'sender_phone' => $resolvedMessage->sender_phone,
                'is_lid' => false,
            ]);
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
