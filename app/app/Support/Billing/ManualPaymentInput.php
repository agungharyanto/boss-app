<?php

namespace App\Support\Billing;

use App\Enums\PaymentMethod;

/**
 * v0.13.5.5 (T4) — detail pencatatan pembayaran MANUAL saat invoice ditandai
 * LUNAS lewat InvoiceService::markPaid(). Dibangun oleh jalur yang memang
 * mencatat metode: "Tandai Lunas" manual UI (InvoiceIndex) + PATCH API
 * (InvoiceController). Perpanjang & migrasi memanggil markPaid() TANPA objek
 * ini → tidak ada baris payments ditulis (perilaku lama dipertahankan).
 *
 * markPaid() menulis baris payments HANYA bila `method !== null` ("tanpa
 * metode → tidak tercatat"). `recordedBy` = id admin pelaku; `referenceNote`
 * opsional; `companyBankAccountId` hanya relevan untuk Transfer Manual.
 */
final class ManualPaymentInput
{
    public function __construct(
        public readonly ?PaymentMethod $method = null,
        public readonly ?int $recordedBy = null,
        public readonly ?string $referenceNote = null,
        public readonly ?int $companyBankAccountId = null,
    ) {}

    public function records(): bool
    {
        return $this->method !== null;
    }
}
