<?php

namespace App\Services\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Enums\WhatsappSessionStatus;
use App\Models\WhatsappSession;
use App\Support\WhatsappHmac;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ValueError;

class WhatsappSessionService
{
    public function __construct(
        private readonly WhatsappHmac $hmac,
        // WhatsappGatewayService cuma depends WhatsappTemplateService —
        // tidak circular. Dipakai SATU tempat: notifikasi WA
        // duplicate_session_attempt di rejectDuplicateSession() di bawah.
        private readonly WhatsappGatewayService $gatewayService,
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
        if (! $this->callGatewayLogout($session->sessionKey())) {
            return false;
        }

        // Reflect segera di sisi Laravel — webhook logged_out yang sama
        // juga akan datang menyusul dan menerapkan status yang sama
        // (idempotent, bukan konflik).
        $this->applyStatus($session, WhatsappSessionStatus::LoggedOut, null, null);

        return true;
    }

    /**
     * HTTP call gateway logout MURNI — diekstrak dari logout() publik di
     * atas supaya bisa dipakai rejectDuplicateSession() TANPA memicu
     * applyStatus(LoggedOut) di akhirnya (yang akan menimpa status
     * `rejected_duplicate` + status_reason yang baru saja di-set jadi
     * "Logout" generik, menghilangkan penjelasan kenapa session ini
     * ditolak).
     */
    private function callGatewayLogout(string $sessionKey): bool
    {
        $baseUrl = config('services.whatsapp_gateway.url');

        if (! $baseUrl) {
            Log::warning('WhatsappSessionService: services.whatsapp_gateway.url not configured, cannot logout.');

            return false;
        }

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

    /**
     * `applyStatus()` adalah SATU-SATUNYA titik `phone_number` ditulis ke
     * DB — dipanggil dari updateStatusFromWebhook() (webhook real-time)
     * DAN reconcileFromGateway() (polling hourly, fallback kalau webhook
     * hilang). Guard duplikat 1-nomor-1-session HARUS di sini, bukan
     * cuma di salah satu pemanggil, supaya KEDUA jalur tertutup —
     * dikonfirmasi lewat investigasi 2026-09-15 sebelum menulis kode ini.
     */
    private function applyStatus(WhatsappSession $session, WhatsappSessionStatus $status, ?string $phoneNumber, ?string $qrCodeData): void
    {
        if ($status === WhatsappSessionStatus::Connected && $phoneNumber !== null) {
            $activeDuplicate = $this->findActiveDuplicateSession($session, $phoneNumber);

            if ($activeDuplicate !== null) {
                $this->rejectDuplicateSession($session, $phoneNumber, $activeDuplicate);

                return;
            }
        }

        $updates = ['status' => $status];

        if ($phoneNumber !== null) {
            $updates['phone_number'] = $phoneNumber;
        }

        if ($qrCodeData !== null) {
            $updates['qr_code_data'] = $qrCodeData;
        }

        if ($status === WhatsappSessionStatus::Connected) {
            $updates['last_connected_at'] = now();
            // rejected_duplicate lama (kalau ada) sudah bukan relevan lagi
            // begitu session ini genuinely berhasil connected bersih.
            $updates['status_reason'] = null;
        } elseif (in_array($status, [WhatsappSessionStatus::Disconnected, WhatsappSessionStatus::LoggedOut], true)) {
            $updates['last_disconnected_at'] = now();
        }

        try {
            $session->update($updates);
        } catch (QueryException $e) {
            // Race condition safety net — SELECT check di atas ("adakah
            // duplikat AKTIF sekarang") lolos tidak menemukan apa pun,
            // tapi SESAAT SETELAHNYA request LAIN untuk phone_number yang
            // SAMA sudah lebih dulu commit sebagai connected (2 webhook
            // hampir bersamaan). Partial unique index
            // whatsapp_sessions_connected_phone_unique (migration
            // 2026_09_15_100000) menolak UPDATE ini secara ATOMIK di
            // level DB — genuinely race, bukan bug logic. Sengaja TIDAK
            // pakai lockForUpdate()/DB::transaction() manual di atas: row
            // lock pada 2 baris BERBEDA (session A vs session B) tidak
            // benar-benar mencegah race ini (baris B belum match filter
            // phone_number SAAT request A membaca), constraint DB di
            // level statement inilah yang jadi source of truth akhir yang
            // genuinely atomik — lebih sederhana dan tidak kalah aman.
            if ($status === WhatsappSessionStatus::Connected && $this->isConnectedPhoneUniqueViolation($e)) {
                $activeDuplicate = $this->findActiveDuplicateSession($session, $phoneNumber);

                if ($activeDuplicate !== null) {
                    $this->rejectDuplicateSession($session, $phoneNumber, $activeDuplicate);

                    return;
                }
            }

            throw $e;
        }
    }

    /**
     * Session lain (session_key BERBEDA — `id != $session->id`) yang
     * SEDANG connected dengan phone_number yang SAMA. Re-pairing nomor
     * yang sama ke session ITU SENDIRI (mis. reseller logout lalu connect
     * ulang) TIDAK match filter `id != $session->id` — bukan duplikat,
     * diizinkan seperti biasa, tanpa logic tambahan apa pun.
     */
    private function findActiveDuplicateSession(WhatsappSession $session, string $phoneNumber): ?WhatsappSession
    {
        return WhatsappSession::withoutGlobalScopes()
            ->where('id', '!=', $session->id)
            ->where('phone_number', $phoneNumber)
            ->where('status', WhatsappSessionStatus::Connected)
            ->first();
    }

    /**
     * SQLSTATE 23505 = Postgres unique_violation (driver produksi).
     * SQLite (driver test suite, phpunit.xml) tidak expose SQLSTATE
     * standar untuk ini — pesan errornya literal mengandung "UNIQUE
     * constraint failed" — dicek keduanya supaya portable ke driver mana
     * pun test suite jalan, gotcha driver yang sudah berulang kali
     * dicatat di CLAUDE.md. `whatsapp_sessions_connected_phone_unique`
     * dicek di pesan supaya tidak salah tangkap unique violation LAIN
     * yang genuinely bukan soal ini (mis. constraint tidak terkait).
     */
    private function isConnectedPhoneUniqueViolation(QueryException $e): bool
    {
        if (($e->errorInfo[0] ?? null) === '23505') {
            return str_contains($e->getMessage(), 'whatsapp_sessions_connected_phone_unique');
        }

        return str_contains($e->getMessage(), 'UNIQUE constraint failed')
            && str_contains($e->getMessage(), 'whatsapp_sessions.phone_number');
    }

    /**
     * Session BARU (baru saja pairing) DITOLAK karena nomornya sudah
     * `connected` di session lain: (a) status jadi rejected_duplicate +
     * status_reason jelas (BUKAN disimpan sebagai connected), (b) paksa
     * logout gateway session BARU (supaya WhatsApp-nya benar-benar
     * terputus, tidak menggantung sebagai linked device kedua — via
     * callGatewayLogout() LANGSUNG, BUKAN logout() publik, supaya
     * webhook logged_out susulan tidak menimpa status_reason yang baru
     * saja di-set), (c) kirim notifikasi WA OTOMATIS ke nomor itu sendiri
     * LEWAT SESSION LAMA yang masih aktif (`$activeSession`, bukan
     * session baru yang barusan ditolak).
     */
    private function rejectDuplicateSession(WhatsappSession $newSession, string $phoneNumber, WhatsappSession $activeSession): void
    {
        $attemptedResellerName = $newSession->reseller_id !== null
            ? $newSession->reseller?->name ?? "Reseller #{$newSession->reseller_id}"
            : 'ISP A (Langsung)';

        Log::warning(
            "WhatsappSessionService: rejected duplicate phone_number={$phoneNumber} for new session_key={$newSession->sessionKey()} — already connected on session_key={$activeSession->sessionKey()}."
        );

        $newSession->update([
            'status' => WhatsappSessionStatus::RejectedDuplicate,
            'status_reason' => "Nomor ini sudah digunakan di BOSS App dengan sesi yang lain ({$attemptedResellerName} mencoba pairing pada ".now()->format('d/m/Y H:i').').',
            'qr_code_data' => null,
        ]);

        $this->callGatewayLogout($newSession->sessionKey());

        $this->gatewayService->buildAndQueueForRecipient(
            WhatsappEventType::DuplicateSessionAttempt,
            $activeSession->tenant_id,
            $phoneNumber,
            [
                'reseller_name' => $attemptedResellerName,
                'attempted_at' => now()->format('d/m/Y H:i'),
                'company_name' => $activeSession->tenant?->name,
            ],
            null,
            $activeSession->reseller_id,
        );
    }
}
