<?php

namespace App\Services\Shared;

use RuntimeException;

/**
 * v0.12.1 — kegagalan alur OTP verifikasi aksi self-service, generic untuk
 * penerima apa pun (Referrer, Technician, ...). Pesannya user-facing
 * (Bahasa Indonesia) — komponen Livewire pemanggil langsung meneruskannya
 * ke `addError()`.
 *
 * Sub-class per-penerima (`ReferrerOtpException`, `TechnicianOtpException`)
 * dipertahankan agar `catch (ReferrerOtpException $e)` di kode lama tetap
 * bekerja setelah `ReferrerActionOtpService` jadi thin wrapper. Thin
 * wrapper menangkap `ActionOtpException` dari service generic lalu
 * melempar ulang sebagai sub-class-nya sendiri lewat `wrap()`.
 */
class ActionOtpException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }

    public static function rateLimited(int $retryAfterSeconds): static
    {
        $minutes = (int) ceil($retryAfterSeconds / 60);

        return new static(
            "Terlalu banyak permintaan kode. Coba lagi dalam ~{$minutes} menit.",
            $retryAfterSeconds,
        );
    }

    public static function deliveryFailed(): static
    {
        return new static('Kode OTP gagal dikirim ke WhatsApp Anda. Hubungi admin — template pesan OTP belum diatur.');
    }

    public static function noCode(): static
    {
        return new static('Belum ada kode aktif untuk aksi ini. Minta kirim kode terlebih dahulu.');
    }

    public static function invalidCode(): static
    {
        return new static('Kode yang Anda masukkan salah. Periksa lagi pesan WhatsApp Anda.');
    }

    public static function tooManyWrongAttempts(): static
    {
        return new static('Kode dimasukkan salah terlalu banyak kali. Minta kirim kode baru.');
    }

    /**
     * Bungkus ulang kegagalan generic sebagai sub-class per-penerima,
     * mempertahankan pesan + `retryAfterSeconds`.
     */
    public static function wrap(ActionOtpException $e): static
    {
        return new static($e->getMessage(), $e->retryAfterSeconds);
    }
}
