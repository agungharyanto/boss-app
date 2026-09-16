<?php

namespace App\Services;

use App\Services\Shared\ActionOtpException;

/**
 * v0.22.4 — kegagalan alur OTP "Lupa Password" akun Staff. Sub-class
 * `ActionOtpException` (service generic bersama), sejajar dengan
 * `App\Services\Commission\ReferrerOtpException` dan
 * `App\Services\Installation\TechnicianOtpException`. Pesan user-facing
 * Bahasa Indonesia.
 */
class StaffOtpException extends ActionOtpException {}
