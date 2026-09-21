<?php

namespace App\Services\Installation;

use App\Exceptions\WorkOrderAlreadyClaimedException;
use App\Models\Technician;
use App\Models\ToolType;
use App\Models\WorkOrder;
use App\Models\WorkOrderClaimPartner;
use App\Models\WorkOrderModemUnit;
use App\Models\WorkOrderToolUsage;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * v0.13.4.1 — Klaim WO via signed-link (public, tanpa login). Satu-satunya
 * tempat logic penyimpanan klaim: partner kerja + alat non-modem + modem
 * per-unit, semuanya dalam SATU transaksi. WO STATUS TIDAK PERNAH disentuh
 * di sini — status tetap sepenuhnya milik alur assignTechnician()/verify()
 * admin yang sudah ada (keputusan Agung, lihat CLAUDE.md).
 *
 * Dipanggil dari WorkOrderClaimController (route signed, tanpa auth:sanctum
 * — teknisi utama TIDAK login, identitasnya datang murni dari signature URL
 * yang sudah divalidasi middleware 'signed' sebelum controller ini
 * dipanggil). "Valid signature = valid authorization, period" — tidak ada
 * perbandingan sesi karena memang tidak ada sesi sama sekali.
 *
 * v0.13.4.1 amendment — klaim PERTAMA yang berhasil mengisi
 * work_orders.claimed_at/claimed_by_technician_id (murni metadata, tidak
 * menyentuh WorkOrderStatus). Klaim BERIKUTNYA ke WO yang sama (link lama
 * yang masih valid, atau link baru dari reminder) DITOLAK
 * (WorkOrderAlreadyClaimedException) — bukan overwrite/duplikat data.
 * Cek claimed_at dilakukan SETELAH `lockForUpdate()` di dalam transaksi
 * yang sama (bukan cek terpisah sebelum transaksi) — menutup window race
 * 2 submit yang genuinely bersamaan, pola sama OdpPort/VpnIpPool.
 */
class WorkOrderClaimService
{
    /**
     * @param  array<int, int>  $partnerTechnicianIds
     * @param  array<int, array{tool_type_id: int, quantity: int, technician_id: int}>  $toolUsages
     * @param  array<int, array{serial_number: string, mac_address: string, technician_id: int}>  $modemUnits
     */
    public function submit(
        WorkOrder $workOrder,
        Technician $claimingTechnician,
        array $partnerTechnicianIds,
        array $toolUsages,
        array $modemUnits,
    ): void {
        // Kumpulan technician_id yang SAH untuk kolom "siapa bawa" — teknisi
        // utama sendiri, atau salah satu partner yang benar-benar dipilih di
        // form yang SAMA. Bukan teknisi lain sembarangan.
        $allowedBearerIds = [...$partnerTechnicianIds, $claimingTechnician->id];

        foreach ($partnerTechnicianIds as $partnerId) {
            if ($partnerId === $claimingTechnician->id) {
                throw new InvalidArgumentException('Partner tidak boleh sama dengan teknisi utama.');
            }

            if (! Technician::query()
                ->where('id', $partnerId)
                ->where('tenant_id', $workOrder->tenant_id)
                ->exists()) {
                throw new InvalidArgumentException("Partner id={$partnerId} tidak valid untuk tenant ini.");
            }
        }

        foreach ($toolUsages as $usage) {
            if (! in_array((int) $usage['technician_id'], $allowedBearerIds, true)) {
                throw new InvalidArgumentException('technician_id pembawa alat harus teknisi utama atau salah satu partner terpilih.');
            }

            if (! ToolType::query()
                ->where('id', $usage['tool_type_id'])
                ->where('tenant_id', $workOrder->tenant_id)
                ->where('is_active', true)
                ->exists()) {
                throw new InvalidArgumentException("tool_type_id={$usage['tool_type_id']} tidak valid untuk tenant ini.");
            }
        }

        foreach ($modemUnits as $unit) {
            if (! in_array((int) $unit['technician_id'], $allowedBearerIds, true)) {
                throw new InvalidArgumentException('technician_id pembawa modem harus teknisi utama atau salah satu partner terpilih.');
            }
        }

        DB::transaction(function () use ($workOrder, $claimingTechnician, $partnerTechnicianIds, $toolUsages, $modemUnits) {
            // Row lock — SELECT ... FOR UPDATE baru di dalam transaksi ini,
            // bukan reuse instance $workOrder yang sudah di-fetch sebelum
            // masuk transaksi. Ini yang genuinely menutup window race 2
            // submit bersamaan (2 tab/klik ganda): request kedua menunggu
            // di baris ini sampai request pertama commit, lalu melihat
            // claimed_at yang sudah terisi request pertama.
            $locked = WorkOrder::withoutGlobalScopes()->whereKey($workOrder->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw new InvalidArgumentException('Work Order tidak ditemukan.');
            }

            if ($locked->claimed_at !== null) {
                throw new WorkOrderAlreadyClaimedException('Work Order ini sudah diklaim sebelumnya.');
            }

            foreach ($partnerTechnicianIds as $partnerId) {
                WorkOrderClaimPartner::firstOrCreate([
                    'work_order_id' => $workOrder->id,
                    'technician_id' => $partnerId,
                ]);
            }

            foreach ($toolUsages as $usage) {
                WorkOrderToolUsage::create([
                    'work_order_id' => $workOrder->id,
                    'tool_type_id' => $usage['tool_type_id'],
                    'technician_id' => $usage['technician_id'],
                    'quantity' => $usage['quantity'],
                ]);
            }

            foreach ($modemUnits as $unit) {
                WorkOrderModemUnit::create([
                    'work_order_id' => $workOrder->id,
                    'technician_id' => $unit['technician_id'],
                    'serial_number' => $unit['serial_number'],
                    'mac_address' => $unit['mac_address'],
                ]);
            }

            // Murni metadata (kapan/siapa klaim) — WorkOrderStatus TIDAK
            // disentuh sama sekali, lihat docblock class.
            $locked->update([
                'claimed_at' => now(),
                'claimed_by_technician_id' => $claimingTechnician->id,
            ]);
        });
    }
}
