<?php

namespace App\Enums;

/**
 * v0.14.2.1 — RouterOS live-push, starting with CustomerIpPool. A row's
 * push to the real Mikrotik router is always async (a queued Job, never
 * blocking the HTTP create/update/delete request) — this tracks the
 * outcome of that latest attempt.
 */
enum MikrotikSyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Synced => 'Tersinkron',
            self::Failed => 'Gagal',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-yellow-100 text-yellow-700',
            self::Synced => 'bg-green-100 text-green-700',
            self::Failed => 'bg-red-100 text-red-700',
        };
    }
}
