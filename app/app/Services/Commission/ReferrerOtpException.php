<?php

namespace App\Services\Commission;

use App\Services\Shared\ActionOtpException;

/**
 * v0.9.6 — kegagalan alur OTP verifikasi aksi Referrer. Pesannya
 * user-facing (Bahasa Indonesia) — komponen Livewire portal langsung
 * meneruskannya ke `addError()`.
 *
 * v0.12.1 — sekarang sub-class `ActionOtpException` (service generic
 * bersama). Dipertahankan sebagai kelas sendiri supaya `catch
 * (ReferrerOtpException $e)` di `ReferrerForgotPassword` dan `CustomerIndex`
 * tetap bekerja tanpa diubah — `ReferrerActionOtpService` (thin wrapper)
 * menangkap `ActionOtpException` dari service generic lalu melempar ulang
 * lewat `ActionOtpException::wrap()`.
 */
class ReferrerOtpException extends ActionOtpException {}
