<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    /**
     * State machine, mirrors App\Enums\CustomerStatus::canTransitionTo():
     * draft -> pending -> (paid | overdue) -> paid; any non-terminal state
     * can be cancelled; paid/cancelled are terminal (no further transitions).
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        if ($this === self::Paid || $this === self::Cancelled) {
            return false;
        }

        if ($target === self::Cancelled) {
            return true;
        }

        return match ($this) {
            self::Draft => $target === self::Pending,
            self::Pending => in_array($target, [self::Paid, self::Overdue], true),
            self::Overdue => $target === self::Paid,
            self::Paid, self::Cancelled => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Cancelled => 'Cancelled',
        };
    }
}
