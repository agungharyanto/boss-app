<?php

namespace App\Support\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Models\Customer;
use App\Models\CustomerAutoSendDeferral;
use App\Models\Invoice;

/**
 * v0.28.3 (T3, B1) — satu kandidat kirim otomatis pelanggan yang dikumpulkan
 * CustomerAutoSendOrchestrator sebelum diurutkan (K13) dan dilewatkan ke
 * gerbang satu per satu.
 *
 * `priority` (kecil = lebih dulu) menentukan urutan K13:
 *   H-0 (0) → H-x terdekat (d) → suspend (100) → invoice terbit (200) →
 *   registrasi (300). `tieBreak` memberi urutan deterministik dalam band yang
 *   sama (invoice_id / customer_id / deferral_id).
 *
 * `deferral` terisi HANYA untuk jalur registrasi (siklus hidup deferral
 * dikelola orkestrator: expired >48 jam, dibatalkan saat skip terminal).
 * `needsAttachment` true HANYA untuk invoice_issued (PDF dibangun saat
 * dispatch, bukan saat kumpul — hindari render dompdf untuk kandidat yang tak
 * jadi dikirim karena cap/jendela).
 */
final class AutoSendCandidate
{
    public function __construct(
        public readonly WhatsappEventType $event,
        public readonly Customer $customer,
        public readonly ?Invoice $invoice,
        public readonly int $priority,
        public readonly int $tieBreak,
        public readonly ?CustomerAutoSendDeferral $deferral = null,
        public readonly bool $needsAttachment = false,
    ) {}
}
