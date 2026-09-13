<?php

namespace App\Services\Shared;

use App\Enums\WhatsappEventType;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\Whatsapp\WhatsappGatewayService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * v0.12.1 — generalisasi `ReferrerActionOtpService` (v0.9.6). OTP 6-digit
 * dikirim ke WhatsApp PENERIMA sendiri (Referrer, Technician, ...) untuk
 * memverifikasi bahwa aksi self-service benar-benar dilakukan orang yang
 * berhak, bukan sesi yang dibajak.
 *
 * Generic by design — tidak tahu tentang model Referrer/Technician; ia
 * hanya menerima identitas penerima dalam bentuk primitif (`recipientType`
 * + `recipientId` + `tenantId` + `recipientPhone` + `recipientName`).
 * Thin wrapper per-penerima (`ReferrerActionOtpService`,
 * `TechnicianActionOtpService`) yang memetakan model ke primitif itu dan
 * menjaga signature publik lama tetap sama.
 *
 * Kode disimpan sementara di cache (Redis, internal-only, BOSS-010) — sama
 * posture `App\Services\Network\ScriptDownloadTokenService`: plaintext,
 * TTL pendek, jumlah percobaan dibatasi, pengiriman ulang di-rate-limit.
 * Terikat ke `(recipientType, recipientId, scope)` — `$scope` menyertakan
 * id sumber daya spesifik (mis. `customer_id`) supaya kode untuk sumber
 * daya A tidak bisa dipakai untuk B.
 *
 * Konstanta (TTL/percobaan/resend) SENGAJA identik dengan
 * `ReferrerActionOtpService` v0.9.6 — UX OTP yang sudah jalan di produksi
 * tidak boleh berubah.
 */
class ActionOtpService
{
    public const TTL_MINUTES = 5;

    public const MAX_WRONG_ATTEMPTS = 5;

    public const RESEND_MAX = 3;

    public const RESEND_WINDOW_MINUTES = 10;

    public function __construct(
        private readonly WhatsappGatewayService $gateway,
    ) {}

    /**
     * `$tenantId` + `$recipientName` — TIDAK ada di sketsa signature awal
     * tapi wajib: template WA di-resolve per-tenant (level ISP,
     * `reseller_id` null) dan template merender nama penerima
     * (`{referrer_name}`/`{recipient_name}`). Kedua thin wrapper punya nilai
     * ini dari model-nya (`$referrer->tenant_id`/`$referrer->name`, dst).
     *
     * `$actionLabel` — deskripsi singkat aksi, dirender ke variabel
     * template `{action_label}` (mis. "mencatat titip pembayaran untuk
     * Budi", "reset password akun Portal Referrer"). Satu event type WA
     * dipakai semua alur — labelnya yang membedakan konteks.
     *
     * @throws ActionOtpException saat rate-limited atau template WA belum di-seed
     */
    public function issue(
        string $recipientType,
        string $recipientId,
        int $tenantId,
        string $recipientPhone,
        string $recipientName,
        string $scope,
        string $actionLabel,
        ?Customer $relatedCustomer = null,
    ): void {
        $rateKey = $this->rateKey($recipientType, $recipientId, $scope);

        if (RateLimiter::tooManyAttempts($rateKey, self::RESEND_MAX)) {
            throw ActionOtpException::rateLimited(RateLimiter::availableIn($rateKey));
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $log = $this->gateway->buildAndQueueForRecipient(
            WhatsappEventType::ReferrerActionOtp,
            $tenantId,
            $recipientPhone,
            [
                'recipient_name' => $recipientName,
                // Alias BC: template ISP yang di-seed v0.9.6 memakai
                // {referrer_name}. Dipertahankan supaya pesan tidak
                // "Halo ," untuk penerima non-Referrer sampai template
                // sempat ditulis ulang pakai {recipient_name}.
                'referrer_name' => $recipientName,
                'company_name' => Tenant::find($tenantId)?->name,
                'customer_name' => $relatedCustomer?->name,
                'otp_code' => $code,
                'otp_minutes' => self::TTL_MINUTES,
                'action_label' => $actionLabel,
            ],
            $relatedCustomer,
        );

        if ($log === null) {
            throw ActionOtpException::deliveryFailed();
        }

        Cache::put($this->cacheKey($recipientType, $recipientId, $scope), [
            'code' => $code,
            'wrong_attempts' => 0,
        ], now()->addMinutes(self::TTL_MINUTES));

        RateLimiter::hit($rateKey, self::RESEND_WINDOW_MINUTES * 60);
    }

    /**
     * @throws ActionOtpException saat kode salah / kedaluwarsa / percobaan habis
     */
    public function verify(string $recipientType, string $recipientId, string $scope, string $code): void
    {
        $key = $this->cacheKey($recipientType, $recipientId, $scope);
        $entry = Cache::get($key);

        if (! is_array($entry) || ! isset($entry['code'])) {
            throw ActionOtpException::noCode();
        }

        if (! hash_equals((string) $entry['code'], trim($code))) {
            $wrong = (int) ($entry['wrong_attempts'] ?? 0) + 1;

            if ($wrong >= self::MAX_WRONG_ATTEMPTS) {
                Cache::forget($key);

                throw ActionOtpException::tooManyWrongAttempts();
            }

            $entry['wrong_attempts'] = $wrong;
            Cache::put($key, $entry, now()->addMinutes(self::TTL_MINUTES));

            throw ActionOtpException::invalidCode();
        }

        Cache::forget($key);
    }

    /**
     * Ada kode aktif yang menunggu diverifikasi untuk scope ini?
     */
    public function hasActiveCode(string $recipientType, string $recipientId, string $scope): bool
    {
        return Cache::has($this->cacheKey($recipientType, $recipientId, $scope));
    }

    private function cacheKey(string $recipientType, string $recipientId, string $scope): string
    {
        return "otp:{$recipientType}:{$recipientId}:{$scope}";
    }

    private function rateKey(string $recipientType, string $recipientId, string $scope): string
    {
        return "otp-send:{$recipientType}:{$recipientId}:{$scope}";
    }
}
