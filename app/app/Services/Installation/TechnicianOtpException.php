<?php

namespace App\Services\Installation;

use App\Services\Shared\ActionOtpException;

/**
 * v0.12.1 — kegagalan alur OTP verifikasi aksi Technician. Sub-class
 * `ActionOtpException` (service generic bersama), sejajar dengan
 * `App\Services\Commission\ReferrerOtpException`. Belum ada pemanggil
 * runtime — `TechnicianActionOtpService` siap dipakai alur Technician API
 * (v0.12.x) begitu dibangun. Pesan user-facing Bahasa Indonesia.
 */
class TechnicianOtpException extends ActionOtpException {}
