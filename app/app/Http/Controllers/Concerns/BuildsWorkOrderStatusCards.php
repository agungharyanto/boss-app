<?php

namespace App\Http\Controllers\Concerns;

use App\Models\WorkOrder;
use Illuminate\View\View;

/**
 * v0.13.4.5 — Kartu status seragam untuk halaman publik WO (klaim/aktivasi/
 * tutup). Satu tempat teks tiap kartu, dipakai bersama ketiga controller
 * signed-link. Semua kartu memakai view('work-orders.status-page') + partial
 * 'work-orders.partials.wo-status-card'. Nama teknisi + jam ditampilkan sesuai
 * keputusan Agung (halaman internal tim teknisi, bukan pelanggan).
 */
trait BuildsWorkOrderStatusCards
{
    private function completedCard(WorkOrder $workOrder, string $pageTitle): View
    {
        $workOrder->loadMissing('claimedByTechnician');

        $detail = "WO #{$workOrder->id} sudah SELESAI";
        if ($workOrder->claimedByTechnician) {
            $detail .= " oleh {$workOrder->claimedByTechnician->name}";
        }
        if ($workOrder->completed_at) {
            $detail .= ', '.$workOrder->completed_at->locale('id')->translatedFormat('d M Y, H:i');
        }
        $detail .= '.';

        return view('work-orders.status-page', [
            'pageTitle' => $pageTitle,
            'icon' => '✅',
            'tone' => 'green',
            'heading' => 'Work Order Selesai',
            'detail' => $detail,
            'workOrder' => $workOrder,
        ]);
    }

    private function cancelledCard(WorkOrder $workOrder, string $pageTitle): View
    {
        return view('work-orders.status-page', [
            'pageTitle' => $pageTitle,
            'icon' => '🚫',
            'tone' => 'red',
            'heading' => 'Work Order Dibatalkan',
            'detail' => "WO #{$workOrder->id} DIBATALKAN. Link ini tidak bisa dipakai lagi.",
            'workOrder' => $workOrder,
        ]);
    }

    private function claimedByOtherCard(WorkOrder $workOrder, string $pageTitle): View
    {
        $workOrder->loadMissing('claimedByTechnician');

        $detail = "WO #{$workOrder->id} sudah diambil oleh "
            .($workOrder->claimedByTechnician?->name ?? 'teknisi lain');
        if ($workOrder->claimed_at) {
            $detail .= ', '.$workOrder->claimed_at->locale('id')->translatedFormat('d M Y, H:i');
        }
        $detail .= '. Link ini tidak bisa dipakai lagi.';

        return view('work-orders.status-page', [
            'pageTitle' => $pageTitle,
            'icon' => '⚠️',
            'tone' => 'amber',
            'heading' => 'Work Order Sudah Diambil',
            'detail' => $detail,
            'workOrder' => $workOrder,
        ]);
    }

    private function notClaimedCard(WorkOrder $workOrder, string $pageTitle, string $detail): View
    {
        return view('work-orders.status-page', [
            'pageTitle' => $pageTitle,
            'icon' => 'ℹ️',
            'tone' => 'gray',
            'heading' => 'Work Order Belum Diambil',
            'detail' => $detail,
            'workOrder' => $workOrder,
        ]);
    }

    private function notActivatedCard(WorkOrder $workOrder, string $pageTitle, ?string $activationUrl): View
    {
        return view('work-orders.status-page', [
            'pageTitle' => $pageTitle,
            'icon' => 'ℹ️',
            'tone' => 'amber',
            'heading' => 'Belum Bisa Ditutup',
            'detail' => "WO #{$workOrder->id} belum diaktivasi (status: {$workOrder->status->label()}). "
                .'Selesaikan aktivasi pelanggan dulu sebelum menutup Work Order.',
            'workOrder' => $workOrder,
            'actionUrl' => $activationUrl,
            'actionLabel' => 'Lanjut ke Aktivasi Pelanggan',
        ]);
    }
}
