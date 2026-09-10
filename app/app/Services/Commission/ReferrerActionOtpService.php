<?php

namespace App\Services\Commission;

use App\Models\Customer;
use App\Models\Referrer;
use App\Services\Shared\ActionOtpException;
use App\Services\Shared\ActionOtpService;

/**
 * v0.9.6 — OTP 6-digit dikirim ke WhatsApp REFERRER sendiri untuk
 * memverifikasi bahwa aksi self-service (mis. mencatat titip pembayaran,
 * reset password Portal Referrer, perpanjang langganan) benar-benar
 * dilakukan Referrer asli, bukan sesi yang dibajak.
 *
 * v0.12.1 — sekarang THIN WRAPPER di atas `App\Services\Shared\
 * ActionOtpService` (generic, penerima apa pun). Signature publik
 * (`issue(Referrer,...)` / `verify(Referrer,...)` / `hasActiveCode(Referrer,
 * ...)`) SENGAJA tidak berubah — `ReferrerForgotPassword` dan
 * `CustomerIndex` (2 pemanggil) tidak perlu disentuh. `recipientType` =
 * `'referrer'`, `recipientId` = `referrer->id`.
 *
 * `ActionOtpException` dari service generic dibungkus ulang jadi
 * `ReferrerOtpException` supaya `catch (ReferrerOtpException $e)` yang sudah
 * ada tetap menangkap.
 */
class ReferrerActionOtpService
{
    public const TTL_MINUTES = ActionOtpService::TTL_MINUTES;

    public const MAX_WRONG_ATTEMPTS = ActionOtpService::MAX_WRONG_ATTEMPTS;

    public const RESEND_MAX = ActionOtpService::RESEND_MAX;

    public const RESEND_WINDOW_MINUTES = ActionOtpService::RESEND_WINDOW_MINUTES;

    private const RECIPIENT_TYPE = 'referrer';

    public function __construct(
        private readonly ActionOtpService $otp,
    ) {}

    /**
     * `$actionLabel` — deskripsi singkat aksi yang dikonfirmasi kode ini,
     * dirender ke variabel template `{action_label}` (mis. "mencatat titip
     * pembayaran untuk Budi", "reset password akun Portal Referrer").
     *
     * @throws ReferrerOtpException saat rate-limited atau template WA belum di-seed
     */
    public function issue(Referrer $referrer, string $scope, string $actionLabel, ?Customer $relatedCustomer = null): void
    {
        try {
            $this->otp->issue(
                self::RECIPIENT_TYPE,
                (string) $referrer->id,
                $referrer->tenant_id,
                $referrer->phone,
                $referrer->name,
                $scope,
                $actionLabel,
                $relatedCustomer,
            );
        } catch (ActionOtpException $e) {
            throw ReferrerOtpException::wrap($e);
        }
    }

    /**
     * @throws ReferrerOtpException saat kode salah / kedaluwarsa / percobaan habis
     */
    public function verify(Referrer $referrer, string $scope, string $code): void
    {
        try {
            $this->otp->verify(self::RECIPIENT_TYPE, (string) $referrer->id, $scope, $code);
        } catch (ActionOtpException $e) {
            throw ReferrerOtpException::wrap($e);
        }
    }

    /**
     * Ada kode aktif yang menunggu diverifikasi untuk scope ini?
     */
    public function hasActiveCode(Referrer $referrer, string $scope): bool
    {
        return $this->otp->hasActiveCode(self::RECIPIENT_TYPE, (string) $referrer->id, $scope);
    }
}
