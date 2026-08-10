<?php

namespace App\Enums;

/**
 * v0.7.4 — "delivered" means the task was successfully enqueued on
 * genieacs-nbi (a real task document with an _id exists), never that the
 * device has actually executed it. Whether GenieACS's own best-effort
 * connection_request attempt succeeded or not is irrelevant to this status
 * — see App\Services\Network\CpeActionService's own docblock for why: v0.7.3
 * (the Connection Request routing this would need) isn't verified working
 * yet, so this module is deliberately built to be correct in "queued, not
 * instant" mode first. There is no "confirmed executed" status — BOSS App
 * has no way to observe that without a device-side signal this sprint.
 */
enum CpeActionStatus: string
{
    case Queued = 'queued';
    case Delivered = 'delivered';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Diantre',
            self::Delivered => 'Terkirim ke GenieACS',
            self::Failed => 'Gagal',
        };
    }
}
