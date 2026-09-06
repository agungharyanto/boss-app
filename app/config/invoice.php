<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Identitas perusahaan untuk CETAKAN invoice
    |--------------------------------------------------------------------------
    |
    | Sprint "perpanjang-invoice-asli-cetak" — tabel `tenants` sengaja minimal
    | (id/uuid/name/slug/is_active saja, lihat CLAUDE.md). Sampai ada sprint
    | "branding per-tenant", detail perusahaan di kepala cetakan invoice
    | diambil dari sini. Nama perusahaan default = nama tenant kalau kosong.
    |
    */

    'company_name' => env('INVOICE_COMPANY_NAME', ''),
    'company_address' => env('INVOICE_COMPANY_ADDRESS', ''),
    'company_phone' => env('INVOICE_COMPANY_PHONE', ''),
    'company_email' => env('INVOICE_COMPANY_EMAIL', ''),
    'footer_note' => env('INVOICE_FOOTER_NOTE', 'Terima kasih atas kepercayaan Anda.'),
];
