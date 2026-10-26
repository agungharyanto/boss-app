<?php

namespace App\Services\Installation;

use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\WorkOrderActivationException;
use App\Models\Customer;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use App\Services\Network\CpeBindingService;
use App\Services\Network\RadcheckWriterService;
use App\Services\Network\TestCredentialSyncService;
use App\Support\RadiusUsernameResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v0.13.4.2.1 — aktivasi pelanggan oleh teknisi (cakupan DASAR, tanpa UI).
 * Menyusun layanan yang sudah ada menjadi satu alur:
 *   salin modem (work_order_modem_units -> work_order_devices) -> bind CPE
 *   (CpeBindingService) -> tulis radcheck/radreply ({cid}@ppp.bajastu.id /
 *   "wifijadipasti" / Framed-Pool paket) -> WorkOrderService::startFromActivation().
 *
 * TIDAK termasuk sub-versi ini: pengiriman WiFi ke ONU (butuh ONU online di
 * GenieACS — v0.13.4.2.2), pemicu OMCI/ZTP, UI/route/controller/WhatsApp.
 *
 * Keputusan terkunci yang ditegakkan di sini (Agung, 2026-10-26):
 * - Username PPPoE SELALU `{cid}@ppp.bajastu.id`, password konstanta
 *   `wifijadipasti`. Pelanggan yang SUDAH punya username LAIN di radcheck
 *   (hasil migrasi) DIBLOKIR. Tanpa `cid` DITOLAK.
 * - WO pindah ke InProgress HANYA lewat `WorkOrderService::startFromActivation()`
 *   (assign ke pengklaim lalu start); aturan transisi tidak dilonggarkan.
 */
class WorkOrderActivationService
{
    public function __construct(
        private readonly CpeBindingService $binding,
        private readonly RadcheckWriterService $radcheck,
        private readonly TestCredentialSyncService $credentials,
        private readonly WorkOrderService $workOrders,
    ) {}

    /**
     * Jalankan aktivasi dasar untuk $workOrder atas nama teknisi $claimer.
     *
     * @return array<string, mixed> ringkasan hasil (dipakai .2.2/UI + test).
     *
     * @throws WorkOrderActivationException pada kegagalan guard mana pun.
     */
    public function prepare(WorkOrder $workOrder, Technician $claimer): array
    {
        // ---- GUARD (read-only, SEBELUM transaksi/penulisan apa pun) ----

        // 1. Hanya teknisi pengklaim (claimed_at terisi DAN pengklaim == aktor).
        if ($workOrder->claimed_at === null
            || (int) $workOrder->claimed_by_technician_id !== (int) $claimer->id) {
            throw WorkOrderActivationException::notClaimedByActor();
        }

        // Status sah (Ready/Assigned) — ditolak dengan pesan jelas lebih dulu;
        // startFromActivation() tetap punya guard sendiri (defense-in-depth).
        if (! in_array($workOrder->status, [WorkOrderStatus::Ready, WorkOrderStatus::Assigned], true)) {
            throw WorkOrderActivationException::illegalStatus($workOrder->status);
        }

        // 2. CID wajib (sumber username PPPoE). Ambil customer via customer_id
        // tanpa global scope — konsisten dgn disiplin "derive dari customer_id,
        // bukan tenant aktor" (lihat CpeDialupHistory, CLAUDE.md v0.8.4).
        $customer = Customer::withoutGlobalScopes()->findOrFail($workOrder->customer_id);
        if ($customer->cid === null || $customer->cid === '') {
            throw WorkOrderActivationException::missingCid();
        }

        $pppoeUsername = "{$customer->cid}@ppp.bajastu.id";

        // 3. radcheck: tolak kalau pelanggan ini sudah punya username LAIN
        // (bukan {cid}@ppp) yang punya baris radcheck — kandidat dari
        // RadiusUsernameResolver (phone_number/legacy_username). {cid}@ppp yang
        // sudah ada TIDAK diblokir (write() idempoten menimpanya).
        $otherCandidates = array_values(array_filter(
            RadiusUsernameResolver::candidatesFor($customer),
            fn (string $candidate): bool => $candidate !== $pppoeUsername,
        ));

        if ($otherCandidates !== []) {
            $existing = DB::connection('radius')->table('radcheck')
                ->whereIn('username', $otherCandidates)
                ->value('username');

            if ($existing !== null) {
                throw WorkOrderActivationException::conflictingRadcheckUsername((string) $existing);
            }
        }

        // 4. modem units (hasil klaim) tidak kosong; unit PERTAMA jadi ONT.
        $units = $workOrder->modemUnits()->orderBy('id')->get();
        if ($units->isEmpty()) {
            throw WorkOrderActivationException::noModemUnits();
        }
        if ($units->count() > 1) {
            Log::warning(
                "WorkOrderActivationService: WO #{$workOrder->id} punya {$units->count()} unit modem — ".
                "memakai unit PERTAMA (SN {$units->first()->serial_number}) sebagai ONT."
            );
        }
        $ont = $units->first();

        // 5. Framed-Pool/VLAN dari paket pelanggan (read-only; TIDAK menyentuh
        // device/GenieACS — deriveLiveWanParams cuma query PppPackage/pool DB).
        // Memastikan tier tersedia SEBELUM menulis radcheck.
        $live = $this->credentials->deriveLiveWanParams($customer);
        if (($live['has_vlan_package'] ?? false) !== true) {
            throw WorkOrderActivationException::noVlanPackage(
                $live['message'] ?? 'Pelanggan belum punya paket ber-VLAN — atur ppp_package_id dulu.'
            );
        }
        $framedPool = (string) $live['framed_pool'];

        // ---- EKSEKUSI ----
        // Transaksi DB UTAMA membungkus copy-modem + bind + startFromActivation.
        // radcheck ada di koneksi 'radius' TERPISAH (BOSS-009) dengan transaksinya
        // sendiri — TIDAK ikut rollback transaksi utama. Urutan sengaja:
        //   copy -> bind -> radcheck -> start
        // sehingga: (a) kalau radcheck GAGAL, exception keluar SEBELUM start →
        // WO tidak pernah InProgress, dan copy/bind di-rollback transaksi utama;
        // (b) kalau start GAGAL setelah radcheck sukses, baris radcheck {cid}@ppp
        // tertinggal tapi HARMLESS (write() idempoten — panggilan ulang menimpa
        // nilai yang sama), sementara copy/bind tetap di-rollback.
        return DB::transaction(function () use ($workOrder, $claimer, $ont, $pppoeUsername, $framedPool) {
            // Salin modem_units -> work_order_devices (device_type ont).
            // Idempoten: key (work_order_id, serial_number) → panggilan ulang
            // meng-update baris yang sama, tidak menggandakan.
            $device = WorkOrderDevice::updateOrCreate(
                ['work_order_id' => $workOrder->id, 'serial_number' => $ont->serial_number],
                ['device_type' => WorkOrderDeviceType::Ont, 'mac_address' => $ont->mac_address, 'scanned_at' => now()],
            );

            // Bind CPE (boleh status pending_first_connect kalau ONU belum inform;
            // WiFi push ditunda ke .2.2 — provisionWifiIfPending() no-op saat pending).
            $cpeDevice = $this->binding->bindFromWorkOrder($workOrder);

            // Tulis kredensial PPPoE SETELAH bind sukses.
            $this->radcheck->write($pppoeUsername, TestCredentialSyncService::PPPOE_PASSWORD, $framedPool);

            // Pindahkan WO ke InProgress (assign ke pengklaim lalu start).
            $workOrder = $this->workOrders->startFromActivation($workOrder, $claimer);

            return [
                'work_order_id' => $workOrder->id,
                'status' => $workOrder->status->value,
                'work_order_device_id' => $device->id,
                'cpe_device_id' => $cpeDevice->id,
                'cpe_status' => $cpeDevice->status?->value,
                'pppoe_username' => $pppoeUsername,
                'framed_pool' => $framedPool,
                'modem_unit_count' => $workOrder->modemUnits()->count(),
            ];
        });
    }
}
