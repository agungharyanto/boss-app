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
