<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * v0.28.1 (T1) — error bisnis opt-out/opt-in pelanggan (pesan aman ditampilkan
 * di UI). Dilempar `CustomerNotificationOptOutService` untuk: alasan kurang
 * dari minimal, atau status sudah sama (tolak tanpa menulis log).
 */
class CustomerOptOutException extends RuntimeException {}
