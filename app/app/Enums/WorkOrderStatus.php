<?php

namespace App\Enums;

enum WorkOrderStatus: string
{
    case PendingOdpCheck = 'pending_odp_check';
    case OdpUnavailable = 'odp_unavailable';
    case PendingVerification = 'pending_verification';
    case Ready = 'ready';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * State machine, mirrors App\Enums\InvoiceStatus::canTransitionTo(): a
     * fixed linear happy path (pending_odp_check -> pending_verification ->
     * ready -> assigned -> in_progress -> completed), with odp_unavailable
     * as a dead-end reachable only from pending_odp_check (no method in
     * WorkOrderService advances it further — only cancel() applies from
     * there), and any non-terminal status can be cancelled. completed/
     * cancelled are terminal.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        if ($this === self::Completed || $this === self::Cancelled) {
            return false;
        }

        if ($target === self::Cancelled) {
            return true;
        }

        return match ($this) {
            self::PendingOdpCheck => in_array($target, [self::PendingVerification, self::OdpUnavailable], true),
            self::OdpUnavailable => false,
            self::PendingVerification => $target === self::Ready,
            self::Ready => $target === self::Assigned,
            self::Assigned => $target === self::InProgress,
            self::InProgress => $target === self::Completed,
            self::Completed, self::Cancelled => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingOdpCheck => 'Menunggu Cek ODP',
            self::OdpUnavailable => 'ODP Tidak Tersedia',
            self::PendingVerification => 'Menunggu Verifikasi',
            self::Ready => 'Siap',
            self::Assigned => 'Ditugaskan',
            self::InProgress => 'Sedang Dikerjakan',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
