<?php

namespace App\Services\Installation;

use App\Models\Customer;
use App\Models\Technician;
use App\Services\Shared\ActionOtpException;
use App\Services\Shared\ActionOtpService;

/**
 * v0.12.1 — OTP 6-digit dikirim ke WhatsApp TECHNICIAN sendiri
 * (`technicians.phone`) untuk memverifikasi aksi self-service teknisi
 * lapangan (mis. konfirmasi Work Order lewat Technician API — v0.12.x).
 *
 * THIN WRAPPER di atas `App\Services\Shared\ActionOtpService`, pola persis
 * `App\Services\Commission\ReferrerActionOtpService`: `recipientType` =
 * `'technician'`, `recipientId` = `technician->id`. `ActionOtpException`
 * dari service generic dibungkus jadi `TechnicianOtpException`.
 *
 * BELUM ADA pemanggil runtime di v0.12.1 (tabel `technicians` masih 0 baris
 * live) — service ini disiapkan + full test coverage saja.
 */
class TechnicianActionOtpService
{
    public const TTL_MINUTES = ActionOtpService::TTL_MINUTES;

    public const MAX_WRONG_ATTEMPTS = ActionOtpService::MAX_WRONG_ATTEMPTS;

    public const RESEND_MAX = ActionOtpService::RESEND_MAX;

    public const RESEND_WINDOW_MINUTES = ActionOtpService::RESEND_WINDOW_MINUTES;

    private const RECIPIENT_TYPE = 'technician';

    public function __construct(
        private readonly ActionOtpService $otp,
    ) {}

    /**
     * @throws TechnicianOtpException saat rate-limited atau template WA belum di-seed
     */
    public function issue(Technician $technician, string $scope, string $actionLabel, ?Customer $relatedCustomer = null): void
    {
        try {
            $this->otp->issue(
                self::RECIPIENT_TYPE,
                (string) $technician->id,
                $technician->tenant_id,
                $technician->phone,
                $technician->name,
                $scope,
                $actionLabel,
                $relatedCustomer,
            );
        } catch (ActionOtpException $e) {
            throw TechnicianOtpException::wrap($e);
        }
    }

    /**
     * @throws TechnicianOtpException saat kode salah / kedaluwarsa / percobaan habis
     */
    public function verify(Technician $technician, string $scope, string $code): void
    {
        try {
            $this->otp->verify(self::RECIPIENT_TYPE, (string) $technician->id, $scope, $code);
        } catch (ActionOtpException $e) {
            throw TechnicianOtpException::wrap($e);
        }
    }

    /**
     * Ada kode aktif yang menunggu diverifikasi untuk scope ini?
     */
    public function hasActiveCode(Technician $technician, string $scope): bool
    {
        return $this->otp->hasActiveCode(self::RECIPIENT_TYPE, (string) $technician->id, $scope);
    }
}
