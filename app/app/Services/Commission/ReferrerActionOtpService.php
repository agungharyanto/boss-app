<?php

namespace App\Services\Commission;

use App\Enums\WhatsappEventType;
use App\Models\Customer;
use App\Models\Referrer;
use App\Services\Whatsapp\WhatsappGatewayService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * v0.9.6 — OTP 6-digit dikirim ke WhatsApp REFERRER sendiri untuk
 * memverifikasi bahwa aksi self-service (mis. mencatat titip pembayaran)
 * benar-benar dilakukan Referrer asli, bukan sesi yang dibajak.
 *
 * Kode disimpan sementara di cache (Redis, internal-only, BOSS-010) —
 * sama posture seperti `App\Services\Network\ScriptDownloadTokenService`:
 * plaintext, TTL pendek, jumlah percobaan dibatasi, pengiriman ulang
 * di-rate-limit. Terikat ke `(referrer, scope)` — `$scope` menyertakan
 * `customer_id` spesifik supaya kode untuk pelanggan A tidak bisa dipakai
 * untuk pelanggan B.
 *
 * Alur:
 *  - `issue()`  → generate + simpan + kirim WA. Rate-limited (RESEND_MAX
 *                 per RESEND_WINDOW_MINUTES per scope).
 *  - `verify()` → cek kode. Salah = hitung percobaan, MAX_WRONG_ATTEMPTS
 *                 kali salah → kode dihapus. Benar = kode dihapus
 *                 (single-use) lalu return void.
 */
class ReferrerActionOtpService
{
    public const TTL_MINUTES = 5;

    public const MAX_WRONG_ATTEMPTS = 5;

    public const RESEND_MAX = 3;

    public const RESEND_WINDOW_MINUTES = 10;

    public function __construct(
        private readonly WhatsappGatewayService $gateway,
    ) {}

    /**
     * `$actionLabel` — deskripsi singkat aksi yang dikonfirmasi kode ini,
     * dirender ke variabel template `{action_label}` (mis. "mencatat titip
     * pembayaran untuk Budi", "reset password akun Portal Referrer"). SATU
     * event type `referrer_action_otp` dipakai beberapa alur — labelnya yang
     * membedakan konteks pesan, bukan template terpisah.
     *
     * @throws ReferrerOtpException saat rate-limited atau template WA belum di-seed
     */
    public function issue(Referrer $referrer, string $scope, string $actionLabel, ?Customer $relatedCustomer = null): void
    {
        $rateKey = $this->rateKey($referrer, $scope);

        if (RateLimiter::tooManyAttempts($rateKey, self::RESEND_MAX)) {
            throw ReferrerOtpException::rateLimited(RateLimiter::availableIn($rateKey));
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $log = $this->gateway->buildAndQueueForReferrer(
            WhatsappEventType::ReferrerActionOtp,
            $referrer,
            [
                'otp_code' => $code,
                'otp_minutes' => self::TTL_MINUTES,
                'action_label' => $actionLabel,
            ],
            $relatedCustomer,
        );

        if ($log === null) {
            throw ReferrerOtpException::deliveryFailed();
        }

        Cache::put($this->cacheKey($referrer, $scope), [
            'code' => $code,
            'wrong_attempts' => 0,
        ], now()->addMinutes(self::TTL_MINUTES));

        RateLimiter::hit($rateKey, self::RESEND_WINDOW_MINUTES * 60);
    }

    /**
     * @throws ReferrerOtpException saat kode salah / kedaluwarsa / percobaan habis
     */
    public function verify(Referrer $referrer, string $scope, string $code): void
    {
        $key = $this->cacheKey($referrer, $scope);
        $entry = Cache::get($key);

        if (! is_array($entry) || ! isset($entry['code'])) {
            throw ReferrerOtpException::noCode();
        }

        if (! hash_equals((string) $entry['code'], trim($code))) {
            $wrong = (int) ($entry['wrong_attempts'] ?? 0) + 1;

            if ($wrong >= self::MAX_WRONG_ATTEMPTS) {
                Cache::forget($key);

                throw ReferrerOtpException::tooManyWrongAttempts();
            }

            $entry['wrong_attempts'] = $wrong;
            Cache::put($key, $entry, now()->addMinutes(self::TTL_MINUTES));

            throw ReferrerOtpException::invalidCode();
        }

        Cache::forget($key);
    }

    /**
     * Ada kode aktif yang menunggu diverifikasi untuk scope ini?
     */
    public function hasActiveCode(Referrer $referrer, string $scope): bool
    {
        return Cache::has($this->cacheKey($referrer, $scope));
    }

    private function cacheKey(Referrer $referrer, string $scope): string
    {
        return "referrer-otp:{$referrer->id}:{$scope}";
    }

    private function rateKey(Referrer $referrer, string $scope): string
    {
        return "referrer-otp-send:{$referrer->id}:{$scope}";
    }
}
