<?php

namespace App\Enums;

enum WebhookProcessingResult: string
{
    case Applied = 'applied';
    case Duplicate = 'duplicate';
    case RejectedSignature = 'rejected_signature';
    case RejectedAmountMismatch = 'rejected_amount_mismatch';
    // Not in the original 4-value spec — added because a webhook whose
    // external_id doesn't match any known invoice is a real, distinct
    // failure mode (typo'd/stale external_id, replay of a deleted invoice's
    // webhook, etc). Silently bucketing it under RejectedAmountMismatch
    // would misclassify it in the reconciliation report; this is a plain
    // string column, not a DB-level enum, so adding a case here needs no
    // migration.
    case RejectedInvoiceNotFound = 'rejected_invoice_not_found';

    public function label(): string
    {
        return match ($this) {
            self::Applied => 'Applied',
            self::Duplicate => 'Duplicate',
            self::RejectedSignature => 'Rejected (Invalid Signature)',
            self::RejectedAmountMismatch => 'Rejected (Amount Mismatch)',
            self::RejectedInvoiceNotFound => 'Rejected (Invoice Not Found)',
        };
    }
}
