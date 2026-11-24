<?php

namespace App\Support\Whatsapp;

use App\Enums\CustomerAutoSendResult;
use App\Models\WhatsappMessageLog;

/**
 * v0.28.2 (T2) — hasil beralasan dari CustomerAutoSendGate: alasan + (bila
 * benar-benar diantre) baris log WA. `log` hanya terisi untuk Queued.
 */
final class CustomerAutoSendDecision
{
    public function __construct(
        public readonly CustomerAutoSendResult $result,
        public readonly ?WhatsappMessageLog $log = null,
        // v0.28.4 (SEBAR-BATCH) — delay batch (detik) yang disuntik saat
        // dispatch, hanya untuk Queued. Dipakai command uji mencetak jeda per
        // pesan; null untuk hasil non-Queued.
        public readonly ?int $delaySeconds = null,
    ) {}

    public function queued(): bool
    {
        return $this->result === CustomerAutoSendResult::Queued;
    }

    public function isRunStop(): bool
    {
        return $this->result->isRunStop();
    }
}
