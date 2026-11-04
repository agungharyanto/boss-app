<?php

namespace App\Exceptions;

use App\Enums\WorkOrderStatus;
use RuntimeException;

/**
 * v0.13.4.5 — dilempar WorkOrderClaimService::submit() (jalur WEB) saat WO
 * yang dituju sudah berstatus terminal (Completed/Cancelled) pada saat lock.
 * Menutup celah: jalur web dulu tidak pernah mengecek status (hanya
 * claimed_at), berbeda dari jalur API WorkOrderService::claim() yang sudah
 * menolak Completed/Cancelled. Cek dilakukan SETELAH lockForUpdate di dalam
 * transaksi yang sama (race-safe), lalu ditangkap eksplisit oleh
 * WorkOrderClaimController yang menampilkan kartu status (SELESAI/DIBATALKAN)
 * — bukan endpoint JSON/API, jadi SENGAJA tidak override render() seperti
 * WorkOrderClaimException.
 */
class WorkOrderNotClaimableException extends RuntimeException
{
    public function __construct(public readonly WorkOrderStatus $status)
    {
        parent::__construct("Work Order ini sudah {$status->label()} — tidak bisa diklaim lagi.");
    }
}
