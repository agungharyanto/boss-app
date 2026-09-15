<?php

namespace App\Services\Whatsapp;

use App\Enums\TechnicianStatus;
use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Support\WhatsappPhone;

/**
 * v0.13.3 — GUARD REUSABLE, belum ada business logic PSB (itu v0.13.4).
 * Satu-satunya tanggung jawab: "apakah nomor ini teknisi yang sah dengan
 * WorkOrder aktif?" — jawaban `null`/`WorkOrder`, tidak lebih.
 *
 * Dikonfirmasi eksplisit (bukan `work_order_technicians`, tabel "klaim"
 * v0.12.3 yang genuinely terpisah dan tidak mengubah status WO):
 * otorisasi = `technicians.phone` (ternormalisasi) cocok DENGAN
 * `work_orders.technician_id` (assignment RESMI admin,
 * `WorkOrderService::assignTechnician()`) DAN status WO ada di
 * [Assigned, InProgress] — dua-duanya status yang genuinely sudah punya
 * technician_id terisi (WorkOrderStatus::canTransitionTo() mengonfirmasi
 * Assigned->InProgress tidak pernah meng-null-kan technician_id).
 */
class WhatsappTechnicianAuthService
{
    /**
     * Normalisasi via App\Support\WhatsappPhone::normalize() DI KEDUA SISI
     * (nomor masuk DAN technicians.phone dari DB) — bukan dibandingkan
     * mentah. Sengaja tidak query SQL langsung dengan technicians.phone
     * apa adanya: kolom itu dikonfirmasi tersimpan format lokal "0xxx" di
     * data nyata saat ini, TAPI membandingkan lewat normalize() di PHP
     * tidak bergantung pada asumsi format penyimpanan itu — lebih robust
     * kalau ada baris legacy/format lain.
     *
     * EDGE CASE — 1 teknisi bisa punya LEBIH DARI 1 WorkOrder aktif
     * sekaligus (di-assign pekerjaan berturut sebelum yang pertama
     * selesai). v0.13.3 BELUM punya cara bertanya "WO yang mana?" ke
     * teknisi (itu genuinely business logic v0.13.4) — method ini
     * memilih WO yang PALING BARU diperbarui (`updated_at` terbesar,
     * mencerminkan assignment/perubahan status TERAKHIR) sebagai asumsi
     * paling masuk akal: teknisi yang mengirim WA umumnya merespons
     * pekerjaan TERBARU yang diberikan padanya. INI KEPUTUSAN YANG
     * DILAPORKAN EKSPLISIT, bukan dipilih diam-diam — perlu ditinjau
     * ulang saat v0.13.4 dibangun kalau ternyata perlu logic pemilihan
     * yang lebih canggih (mis. tanya teknisi WO mana yang dimaksud).
     */
    public function resolveAuthorizedTechnicianForActiveWorkOrder(string $phone): ?WorkOrder
    {
        $normalizedPhone = WhatsappPhone::normalize($phone);

        if ($normalizedPhone === '') {
            return null;
        }

        $candidates = WorkOrder::withoutGlobalScopes()
            ->whereIn('status', [WorkOrderStatus::Assigned, WorkOrderStatus::InProgress])
            ->whereNotNull('technician_id')
            ->with('technician')
            ->get();

        $matches = $candidates->filter(function (WorkOrder $workOrder) use ($normalizedPhone) {
            $technician = $workOrder->technician;

            return $technician !== null
                && $technician->status === TechnicianStatus::Active
                && WhatsappPhone::normalize($technician->phone) === $normalizedPhone;
        });

        return $matches->sortByDesc('updated_at')->first();
    }
}
