<?php

namespace App\Exceptions;

use App\Enums\WorkOrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * v0.13.4.3.1 — kegagalan guard penutupan WO via form web teknisi. Pola sama
 * WorkOrderActivationException: static factory (pesan Indonesia per penyebab),
 * render() -> envelope 422. Dipakai WorkOrderCloseService; controller web
 * menangkapnya untuk menampilkan ulang form dengan pesan (bukan 422 mentah).
 */
class WorkOrderCloseException extends RuntimeException
{
    public static function notClaimedByActor(): self
    {
        return new self('Penutupan hanya boleh dilakukan oleh teknisi yang mengklaim Work Order ini.');
    }

    public static function notInProgress(WorkOrderStatus $status): self
    {
        return new self(
            "Work Order berstatus \"{$status->label()}\" tidak bisa ditutup lewat form ini — ".
            'hanya status "Dikerjakan" (In Progress, setelah aktivasi) yang boleh.'
        );
    }

    public static function onuNotReady(bool $onuOnline, bool $wifiSent): self
    {
        $parts = [];
        if (! $onuOnline) {
            $parts[] = 'ONU belum online di sistem';
        }
        if (! $wifiSent) {
            $parts[] = 'WiFi belum terkirim ke perangkat';
        }

        return new self(
            'Belum bisa menutup Work Order: '.implode(' dan ', $parts).'. '.
            'Tunggu hingga ONU online dan WiFi terkirim (halaman akan menyegar otomatis), lalu coba lagi.'
        );
    }

    public static function customerNameMismatch(): self
    {
        return new self('Nama pelanggan yang diketik tidak cocok dengan data Work Order ini. Periksa kembali ejaan nama pelanggan.');
    }

    public static function photosIncomplete(string $detail): self
    {
        return new self($detail);
    }

    public static function signatureMissing(): self
    {
        return new self('Tanda tangan pelanggan wajib diisi — minta pelanggan menandatangani di layar.');
    }

    public static function signatureInvalid(): self
    {
        return new self('Tanda tangan tidak valid (harus berupa gambar PNG yang benar dan tidak kosong). Ulangi tanda tangan.');
    }

    public static function signatureTooLarge(): self
    {
        return new self('Tanda tangan terlalu besar. Ulangi tanda tangan.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'data' => null,
            'meta' => [],
        ], 422);
    }
}
