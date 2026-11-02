<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * v0.13.5.1 — lampiran media WA tidak valid (path di luar prefiks yang
 * diizinkan, traversal, file tidak ada, MIME/ukuran tidak lolos saat kirim).
 * Diperlakukan sebagai kegagalan PERMANEN oleh job (tidak di-retry — file
 * yang tidak ada / MIME salah tidak akan "membaik" dalam 30 detik).
 */
class WhatsappAttachmentException extends RuntimeException {}
