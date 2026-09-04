<?php

namespace App\Services\Commission;

use RuntimeException;

/**
 * v0.9.6 — kegagalan alur OTP verifikasi aksi Referrer. Pesannya
 * user-facing (Bahasa Indonesia) — komponen Livewire portal langsung
 * meneruskannya ke `addError()`.
 */
class ReferrerOtpException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        $minutes = (int) ceil($retryAfterSeconds / 60);

        return new self(
            "Terlalu banyak permintaan kode. Coba lagi dalam ~{$minutes} menit.",
            $retryAfterSeconds,
        );
    }

    public static function deliveryFailed(): self
    {
        return new self('Kode OTP gagal dikirim ke WhatsApp Anda. Hubungi admin — template pesan OTP belum diatur.');
    }

    public static function noCode(): self
    {
        return new self('Belum ada kode aktif untuk aksi ini. Minta kirim kode terlebih dahulu.');
    }

    public static function invalidCode(): self
    {
        return new self('Kode yang Anda masukkan salah. Periksa lagi pesan WhatsApp Anda.');
    }

    public static function tooManyWrongAttempts(): self
    {
        return new self('Kode dimasukkan salah terlalu banyak kali. Minta kirim kode baru.');
    }
}
