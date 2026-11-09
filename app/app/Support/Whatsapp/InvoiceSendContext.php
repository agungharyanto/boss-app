<?php

namespace App\Support\Whatsapp;

/**
 * v0.13.5.3 — konteks kirim MANUAL PDF invoice ke pelanggan. Dibangun HANYA
 * oleh `App\Services\Billing\InvoicePdfSendService` (dijaga test arsitektur
 * `WhatsappManualBypassArchitectureTest`) — kehadiran objek ini pada
 * `WhatsappGatewayService::buildAndQueue()` adalah SATU-SATUNYA cara melewati
 * saklar `customer_auto_notify_enabled`.
 *
 * Semua pemanggil OTOMATIS (4 event pelanggan lama + command terjadwal)
 * memanggil `buildAndQueue()` TANPA konteks ini → tetap ber-guard saklar.
 *
 * Fields:
 *  - recipientOverride: nomor tujuan pengganti (Mode Uji — sudah dinormalisasi
 *    62...). null = kirim ke nomor pelanggan asli.
 *  - isTest: tandai baris log sebagai uji (tidak dihitung "terkirim ke
 *    pelanggan").
 *  - captionPrefix: prefiks caption (mis. "[UJI untuk: Nama - INV/...]\n").
 *  - sentByUserId: id admin pelaku kirim manual.
 */
final class InvoiceSendContext
{
    public function __construct(
        public readonly ?string $recipientOverride = null,
        public readonly bool $isTest = false,
        public readonly string $captionPrefix = '',
        public readonly ?int $sentByUserId = null,
    ) {}
}
