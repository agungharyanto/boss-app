<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * v0.13.5.3 — kegagalan "bisnis" saat kirim PDF invoice manual (status salah,
 * nomor kosong, template nonaktif, sesi mati, anti-spam kirim ulang, Mode Uji
 * tanpa nomor uji). Pesan sudah berbahasa Indonesia & aman ditampilkan ke
 * admin — Livewire menangkapnya dan menampilkan flash/error, BUKAN 500.
 */
class InvoicePdfSendException extends RuntimeException {}
