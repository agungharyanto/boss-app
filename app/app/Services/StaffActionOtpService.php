<?php

namespace App\Services;

use App\Models\User;
use App\Services\Shared\ActionOtpException;
use App\Services\Shared\ActionOtpService;

/**
 * v0.22.4 — OTP 6-digit dikirim ke WhatsApp STAFF sendiri (`users.phone`)
 * untuk memverifikasi alur "Lupa Password" khusus akun staff.
 *
 * THIN WRAPPER di atas `App\Services\Shared\ActionOtpService`, pola persis
 * `App\Services\Commission\ReferrerActionOtpService` dan
 * `App\Services\Installation\TechnicianActionOtpService`: `recipientType` =
 * `'staff'`, `recipientId` = `user->id`. `ActionOtpException` dari service
 * generic dibungkus jadi `StaffOtpException`.
 *
 * REUSE `WhatsappEventType::ReferrerActionOtp` (bukan event type baru) —
 * event ini sudah generic by design sejak v0.9.6, dibedakan `action_label`,
 * bukan tabel/template per-penerima terpisah (dikonfirmasi Agung saat
 * kickoff v0.22.4, bukan diasumsikan).
 */
class StaffActionOtpService
{
    public const TTL_MINUTES = ActionOtpService::TTL_MINUTES;

    public const MAX_WRONG_ATTEMPTS = ActionOtpService::MAX_WRONG_ATTEMPTS;

    public const RESEND_MAX = ActionOtpService::RESEND_MAX;

    public const RESEND_WINDOW_MINUTES = ActionOtpService::RESEND_WINDOW_MINUTES;

    private const RECIPIENT_TYPE = 'staff';

    public function __construct(
        private readonly ActionOtpService $otp,
    ) {}

    /**
     * @throws StaffOtpException saat rate-limited atau template WA belum di-seed
     */
    public function issue(User $staff, string $scope, string $actionLabel): void
    {
        try {
            $this->otp->issue(
                self::RECIPIENT_TYPE,
                (string) $staff->id,
                $staff->tenant_id,
                $staff->phone,
                $staff->name,
                $scope,
                $actionLabel,
            );
        } catch (ActionOtpException $e) {
            throw StaffOtpException::wrap($e);
        }
    }

    /**
     * @throws StaffOtpException saat kode salah / kedaluwarsa / percobaan habis
     */
    public function verify(User $staff, string $scope, string $code): void
    {
        try {
            $this->otp->verify(self::RECIPIENT_TYPE, (string) $staff->id, $scope, $code);
        } catch (ActionOtpException $e) {
            throw StaffOtpException::wrap($e);
        }
    }

    /**
     * Ada kode aktif yang menunggu diverifikasi untuk scope ini?
     */
    public function hasActiveCode(User $staff, string $scope): bool
    {
        return $this->otp->hasActiveCode(self::RECIPIENT_TYPE, (string) $staff->id, $scope);
    }
}
