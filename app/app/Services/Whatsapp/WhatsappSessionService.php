<?php

namespace App\Services\Whatsapp;

use App\Enums\WhatsappSessionStatus;
use App\Models\WhatsappSession;
use App\Support\WhatsappHmac;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ValueError;

class WhatsappSessionService
{
    public function __construct(
        private readonly WhatsappHmac $hmac,
    ) {}

    /**
     * POST /api/v1/whatsapp/webhook/session-status handler logic. Signature
     * is verified BEFORE the payload is trusted at all — same "reject
     * before touching payload" order as PaymentService::handleWebhook().
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateStatusFromWebhook(string $rawBody, ?string $signature, ?string $timestampHeader, array $payload): bool
    {
        if ($signature === null || $timestampHeader === null || ! ctype_digit($timestampHeader)
            || ! $this->hmac->verify($rawBody, $signature, (int) $timestampHeader)) {
            Log::warning('WhatsappSessionService: rejected webhook with invalid/missing HMAC signature.');

            return false;
        }

        $sessionKey = $payload['session_key'] ?? null;
        $status = $payload['status'] ?? null;

        if (! is_string($sessionKey) || $sessionKey === '' || ! is_string($status)) {
            Log::warning('WhatsappSessionService: webhook payload missing session_key/status.');

            return false;
        }

        $session = $this->resolveSessionByKey($sessionKey);

        if ($session === null) {
            Log::warning("WhatsappSessionService: no whatsapp_sessions row for session_key={$sessionKey}.");

            return false;
        }

        try {
            $this->applyStatus(
                $session,
                WhatsappSessionStatus::from($status),
                $payload['phone_number'] ?? null,
                $payload['qr_code_data'] ?? null,
            );
        } catch (ValueError) {
            Log::warning("WhatsappSessionService: unknown status '{$status}' for session_key={$sessionKey}.");

            return false;
        }

        return true;
    }

    /**
     * Creates the whatsapp_sessions row for a reseller (or the "direct"
     * session when $resellerId is null) and immediately kicks off the
     * gateway-side connect via one refreshQrCode() call — the gateway
     * generates the actual QR asynchronously and pushes it back via the
     * connection.update webhook shortly after, so the row returned here
     * may still have qr_code_data=null; the UI polls (re-renders from DB)
     * until the webhook lands.
     */
    public function createSession(int $tenantId, ?int $resellerId): WhatsappSession
    {
        $session = WhatsappSession::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'reseller_id' => $resellerId,
            'status' => WhatsappSessionStatus::QrPending,
        ]);

        $this->refreshQrCode($session);

        return $session->fresh();
    }

    /**
     * Pulls the latest QR code data for one session — used by the
     * Konfigurasi tab's "refresh QR" button.
     */
    public function refreshQrCode(WhatsappSession $session): ?string
    {
        $baseUrl = config('services.whatsapp_gateway.url');

        if (! $baseUrl) {
            Log::warning('WhatsappSessionService: services.whatsapp_gateway.url not configured, cannot refresh QR.');

            return null;
        }

        $sessionKey = $session->sessionKey();
        $timestamp = time();
        $signature = $this->hmac->sign('', $timestamp);

        $response = Http::withHeaders([
            'X-Whatsapp-Timestamp' => (string) $timestamp,
            'X-Whatsapp-Signature' => $signature,
        ])->get(rtrim($baseUrl, '/')."/sessions/{$sessionKey}/qr");

        if (! $response->successful()) {
            Log::error("WhatsappSessionService: failed to fetch QR for session_key={$sessionKey}, HTTP {$response->status()}");

            return null;
        }

        $qrCodeData = $response->json('qr_code_data');

        if ($qrCodeData !== null) {
            $session->update(['qr_code_data' => $qrCodeData, 'status' => WhatsappSessionStatus::QrPending]);
        }

        return $qrCodeData;
    }

    /**
     * "Kode Pairing" — alternatif scan QR saat menghubungkan sesi. HANYA
     * berlaku untuk sesi yang BELUM terhubung — gateway sendiri menolak
     * (500) kalau dipanggil pada sesi yang statusnya `connected`.
     *
     * Sama seperti `refreshQrCode()`, ini me-wipe state sesi dan memulai
     * pairing dari nol — nomor HP yang dimasukkan JADI nomor baru sesi ini
     * begitu berhasil terhubung.
     *
     * @return ?string kode 8 karakter (mis. "ABCD-1234"), atau null kalau gagal
     */
    public function requestPairingCode(WhatsappSession $session, string $phoneNumber): ?string
    {
        $baseUrl = config('services.whatsapp_gateway.url');

        if (! $baseUrl) {
            Log::warning('WhatsappSessionService: services.whatsapp_gateway.url not configured, cannot request pairing code.');

            return null;
        }

        $sessionKey = $session->sessionKey();
        $body = json_encode(['phone_number' => $phoneNumber], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = $this->hmac->sign($body, $timestamp);

        $response = Http::withBody($body, 'application/json')
            ->withHeaders([
                'X-Whatsapp-Timestamp' => (string) $timestamp,
                'X-Whatsapp-Signature' => $signature,
            ])
            ->post(rtrim($baseUrl, '/')."/sessions/{$sessionKey}/pair");

        if (! $response->successful()) {
            Log::error("WhatsappSessionService: failed to request pairing code for session_key={$sessionKey}, HTTP {$response->status()}: {$response->json('message')}");

            return null;
        }

        // Sesi kembali ke qr_pending sisi Laravel — belum benar-benar
        // terhubung, cuma menunggu kode dimasukkan di HP. Webhook
        // connection.update yang sama seperti alur QR akan meng-update ke
        // `connected` begitu berhasil.
        $session->update(['status' => WhatsappSessionStatus::QrPending, 'qr_code_data' => null]);

        return $response->json('pairing_code');
    }

    /**
     * whatsapp:check-session-health's hourly reconciliation — actively
     * pulls GET /sessions rather than only relying on connection.update
     * webhooks, in case a webhook delivery was missed.
     */
    public function reconcileFromGateway(): void
    {
        $baseUrl = config('services.whatsapp_gateway.url');

        if (! $baseUrl) {
            Log::warning('WhatsappSessionService: services.whatsapp_gateway.url not configured, skipping health check.');

            return;
        }

        $timestamp = time();
        $signature = $this->hmac->sign('', $timestamp);

        $response = Http::withHeaders([
            'X-Whatsapp-Timestamp' => (string) $timestamp,
            'X-Whatsapp-Signature' => $signature,
        ])->get(rtrim($baseUrl, '/').'/sessions');

        if (! $response->successful()) {
            Log::error('WhatsappSessionService: failed to fetch /sessions from gateway, HTTP '.$response->status());

            return;
        }

        foreach ((array) $response->json('sessions', []) as $row) {
            $sessionKey = $row['session_key'] ?? null;
            $status = $row['status'] ?? null;

            if (! is_string($sessionKey) || ! is_string($status)) {
                continue;
            }

            $session = $this->resolveSessionByKey($sessionKey);

            if ($session === null) {
                continue;
            }

            try {
                $this->applyStatus($session, WhatsappSessionStatus::from($status), $row['phone_number'] ?? null, null);
            } catch (ValueError) {
                Log::warning("WhatsappSessionService: unknown status '{$status}' from gateway for session_key={$sessionKey}.");
            }
        }
    }

    /**
     * Tombol "Logout" — memanggil `client.Logout(ctx)` whatsmeow
     * SUNGGUHAN di sisi gateway (bukan sekadar wipe lokal) supaya entri
     * "Perangkat Tertaut" di HP pengguna ikut bersih di sisi WhatsApp
     * sendiri — lihat `whatsapp-gateway/internal/session/manager.go::Logout()`.
     *
     * BUG NYATA yang sempat ditemukan+diperbaiki di sini (era migrasi
     * whatsmeow, sesi debugging "masih gagal berkali-kali"): signature
     * sempat di-sign atas string kosong (''), tapi `Http::post($url)`
     * tanpa argumen body kedua diam-diam mengirim body "[]" (default
     * Laravel Http client, BUKAN string kosong) — signature yang diterima
     * gateway tidak pernah cocok dengan apa yang benar-benar dikirim, jadi
     * setiap klik tombol Logout ditolak 401 oleh verifyHmac. Fix: kirim
     * body string kosong EKSPLISIT dan sign string yang sama persis — pola
     * identik `requestPairingCode()`/`SendWhatsappMessageJob`.
     */
    public function logout(WhatsappSession $session): bool
    {
        $baseUrl = config('services.whatsapp_gateway.url');

        if (! $baseUrl) {
            Log::warning('WhatsappSessionService: services.whatsapp_gateway.url not configured, cannot logout.');

            return false;
        }

        $sessionKey = $session->sessionKey();
        $timestamp = time();
        $body = '';
        $signature = $this->hmac->sign($body, $timestamp);

        $response = Http::withBody($body, 'application/json')
            ->withHeaders([
                'X-Whatsapp-Timestamp' => (string) $timestamp,
                'X-Whatsapp-Signature' => $signature,
            ])->post(rtrim($baseUrl, '/')."/sessions/{$sessionKey}/logout");

        if (! $response->successful()) {
            Log::error("WhatsappSessionService: logout failed for session_key={$sessionKey}, HTTP {$response->status()}: {$response->body()}");

            return false;
        }

        // Reflect segera di sisi Laravel — webhook logged_out yang sama
        // juga akan datang menyusul dan menerapkan status yang sama
        // (idempotent, bukan konflik).
        $this->applyStatus($session, WhatsappSessionStatus::LoggedOut, null, null);

        return true;
    }

    /**
     * A non-null reseller_id is globally unique (resellers.id is a
     * platform-wide PK, not per-tenant), so it resolves unambiguously on
     * its own. The bare literal "direct" is NOT globally unique the moment
     * more than one tenant runs this module against the same
     * whatsapp-gateway container — this codebase currently operates as a
     * single-ISP deployment (same assumption CLAUDE.md documents for
     * payment_gateway_settings), so this picks the one existing direct
     * session. A true multi-tenant SaaS rollout would need a
     * tenant-qualified session_key instead of the bare "direct" literal.
     */
    private function resolveSessionByKey(string $sessionKey): ?WhatsappSession
    {
        if ($sessionKey === 'direct') {
            return WhatsappSession::withoutGlobalScopes()->whereNull('reseller_id')->first();
        }

        if (! ctype_digit($sessionKey)) {
            return null;
        }

        return WhatsappSession::withoutGlobalScopes()->where('reseller_id', (int) $sessionKey)->first();
    }

    private function applyStatus(WhatsappSession $session, WhatsappSessionStatus $status, ?string $phoneNumber, ?string $qrCodeData): void
    {
        $updates = ['status' => $status];

        if ($phoneNumber !== null) {
            $updates['phone_number'] = $phoneNumber;
        }

        if ($qrCodeData !== null) {
            $updates['qr_code_data'] = $qrCodeData;
        }

        if ($status === WhatsappSessionStatus::Connected) {
            $updates['last_connected_at'] = now();
        } elseif (in_array($status, [WhatsappSessionStatus::Disconnected, WhatsappSessionStatus::LoggedOut], true)) {
            $updates['last_disconnected_at'] = now();
        }

        $session->update($updates);
    }
}
