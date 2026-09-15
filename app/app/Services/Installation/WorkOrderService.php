<?php

namespace App\Services\Installation;

use App\Enums\OdpPortStatus;
use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderPhotoType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\IncompleteWorkOrderException;
use App\Exceptions\InvalidWorkOrderStatusTransitionException;
use App\Exceptions\WorkOrderClaimException;
use App\Exceptions\WorkOrderNotConfirmedException;
use App\Models\OdpPort;
use App\Models\Subscription;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use App\Models\WorkOrderTechnician;
use App\Services\Network\CpeBindingService;
use App\Services\Network\WanConfigPushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkOrderService
{
    public function __construct(
        private readonly OdpLocatorService $odpLocator,
        private readonly CpeBindingService $cpeBinding,
        private readonly TechnicianActionOtpService $technicianOtp,
        private readonly WanConfigPushService $wanConfigPush,
    ) {}

    /**
     * pending_odp_check -> (pending_verification + port reserved) or
     * odp_unavailable, depending on whether OdpLocatorService finds a free
     * port. The port is re-checked under a row lock right before reserving
     * it (not just trusted from the locate query) to avoid two work orders
     * racing for the same port between locate and reserve.
     *
     * v0.26.1 — `$scheduledAt` (nullable) is the customer's specific
     * visit-appointment day+time, set by sales/admin at creation time.
     * Stored as-is on `work_orders.scheduled_at`, nothing else reads it
     * yet — v0.26.2 (a scheduled command, NOT built this sub-version)
     * will use it to decide WHEN a work order gets dispatched to its
     * assigned technician: a null value means "no specific appointment,
     * dispatch immediately once assigned"; a real value means "dispatch
     * exactly 2 hours before this timestamp". This method itself does
     * NOT trigger any dispatch/notification — v0.26.1 is wiring only.
     */
    public function createFromSubscription(Subscription $subscription, ?string $scheduledAt = null): WorkOrder
    {
        return DB::transaction(function () use ($subscription, $scheduledAt) {
            $workOrder = WorkOrder::create([
                'tenant_id' => $subscription->tenant_id,
                'reseller_id' => $subscription->reseller_id,
                'subscription_id' => $subscription->id,
                'customer_id' => $subscription->customer_id,
                'status' => WorkOrderStatus::PendingOdpCheck,
                'scheduled_at' => $scheduledAt,
            ]);

            $candidate = $this->odpLocator->findNearestAvailable($subscription->customer);

            if ($candidate === null) {
                $this->transition($workOrder, WorkOrderStatus::OdpUnavailable);

                return $workOrder->fresh();
            }

            $port = OdpPort::whereKey($candidate->id)->lockForUpdate()->first();

            if ($port === null || $port->status !== OdpPortStatus::Available) {
                $this->transition($workOrder, WorkOrderStatus::OdpUnavailable);

                return $workOrder->fresh();
            }

            $port->update(['status' => OdpPortStatus::Reserved]);
            $workOrder->update(['odp_id' => $port->odp_id, 'odp_port_id' => $port->id]);
            $this->transition($workOrder, WorkOrderStatus::PendingVerification);

            return $workOrder->fresh();
        });
    }

    /**
     * Only actually transitions to Ready when equipmentReady is true AND a
     * port has already been reserved — a false equipmentReady simply
     * records the flag and leaves the work order at pending_verification
     * (not an error, just "not ready yet").
     */
    public function verify(WorkOrder $workOrder, bool $equipmentReady): WorkOrder
    {
        $workOrder->update(['equipment_ready' => $equipmentReady]);

        if ($equipmentReady && $workOrder->odp_port_id !== null) {
            $this->transition($workOrder, WorkOrderStatus::Ready);
        }

        return $workOrder->fresh();
    }

    /**
     * v0.26.1 — set/ubah/kosongkan janji hari+jam kunjungan SETELAH work
     * order sudah dibuat (mis. sales belum tahu jadwalnya saat create,
     * atau perlu reschedule) — genuinely satu-satunya jalur "edit"
     * lain selain `createFromSubscription()`, dipakai dari `WorkOrderShow`
     * (tidak ada create-form UI di codebase ini per investigasi v0.26.0
     * Langkah 0 — WO dibuat API-only, jadi UI yang relevan untuk field ini
     * adalah halaman detail WO yang sudah ada, bukan form create baru).
     * Sengaja TIDAK membatasi status WO yang boleh di-reschedule — tidak
     * diminta secara eksplisit, dan status Completed/Cancelled tetap
     * boleh punya scheduled_at historis yang benar.
     */
    public function scheduleVisit(WorkOrder $workOrder, ?string $scheduledAt): WorkOrder
    {
        $workOrder->update(['scheduled_at' => $scheduledAt]);

        return $workOrder->fresh();
    }

    public function assignTechnician(WorkOrder $workOrder, Technician $technician): WorkOrder
    {
        $this->transition($workOrder, WorkOrderStatus::Assigned);
        $workOrder->update(['technician_id' => $technician->id]);

        return $workOrder->fresh();
    }

    /**
     * v0.12.3 — klaim mandiri (self-service) lewat API, GENUINELY terpisah
     * dari assignTechnician() di atas: TIDAK mengubah status WO, TIDAK
     * menyentuh `technician_id` sama sekali — lihat WorkOrderTechnician's
     * own docblock untuk kenapa dua mekanisme ini sengaja tidak disinkronkan.
     * Idempoten: klaim ulang oleh teknisi yang sama TIDAK membuat baris
     * kedua (unique(work_order_id, technician_id) di DB adalah jaring
     * pengaman terakhir, firstOrCreate() di sini adalah jalur normalnya).
     *
     * @throws WorkOrderClaimException kalau WO sudah completed/cancelled.
     */
    public function claim(WorkOrder $workOrder, Technician $technician): WorkOrderTechnician
    {
        if (in_array($workOrder->status, [WorkOrderStatus::Completed, WorkOrderStatus::Cancelled], true)) {
            throw new WorkOrderClaimException(
                "Work order ini sudah {$workOrder->status->label()} — tidak bisa diklaim lagi."
            );
        }

        return WorkOrderTechnician::query()->firstOrCreate(
            ['work_order_id' => $workOrder->id, 'technician_id' => $technician->id],
            ['claimed_at' => now()],
        );
    }

    public function start(WorkOrder $workOrder): WorkOrder
    {
        $this->transition($workOrder, WorkOrderStatus::InProgress);

        return $workOrder->fresh();
    }

    /**
     * State-machine legality is checked FIRST (an illegal jump, e.g.
     * pending_verification -> completed, must fail as a transition error
     * even if photos/devices happen to be incomplete too — the transition
     * check takes priority over the readiness check). Only once the jump
     * itself is legal do we require all 4 photo types and at least 1
     * scanned device — then (v0.12.7 Langkah 3) that the technician has
     * confirmed the installation via WhatsApp OTP. Confirmation is checked
     * LAST, after readiness, on purpose: a technician fills in photos/
     * devices first, THEN requests+verifies the OTP as the final step
     * before submitting "Selesai" — matching the real-world order of
     * operations, not an arbitrary code-ordering choice.
     */
    public function complete(WorkOrder $workOrder): WorkOrder
    {
        if (! $workOrder->status->canTransitionTo(WorkOrderStatus::Completed)) {
            throw new InvalidWorkOrderStatusTransitionException($workOrder->status, WorkOrderStatus::Completed);
        }

        $this->assertReadyToComplete($workOrder);

        if ($workOrder->technician_confirmed_at === null) {
            throw new WorkOrderNotConfirmedException;
        }

        $workOrder->update(['status' => WorkOrderStatus::Completed, 'completed_at' => now()]);

        if ($workOrder->odp_port_id !== null) {
            $workOrder->odpPort->update(['status' => OdpPortStatus::Used]);
        }

        // v0.7.1 GenieACS — best-effort, never blocks completing the work
        // order itself (same "catch, log, don't fail the caller's own
        // transaction" posture as WhatsappGatewayService::buildAndQueue()).
        // A technician standing at the customer's premises shouldn't be
        // stuck unable to close out an installation because genieacs-nbi
        // had a momentary hiccup — ReconcileCpeDevices' reconciliation loop
        // exists precisely so a binding that didn't happen here can still
        // resolve later.
        $cpeDevice = null;
        try {
            $cpeDevice = $this->cpeBinding->bindFromWorkOrder($workOrder);
        } catch (Throwable $e) {
            Log::warning("WorkOrderService: CPE binding failed for work order #{$workOrder->id} — {$e->getMessage()}");
        }

        // v0.12.7 Langkah 4 — Push Konfig untuk instalasi BARU (beda dari
        // hook Ganti Paket di SubscriptionRenewalService::renew(), v0.12.6
        // — di sana push dipicu perubahan ppp_package_id, di sini dipicu
        // instalasi PSB selesai). Best-effort, SAMA posture dengan binding
        // di atas dan hook renew() — kegagalan push (device belum pernah
        // connect, tidak ada Template cocok, dll) TIDAK BOLEH membatalkan
        // penyelesaian work order itu sendiri; teknisi yang sudah berdiri
        // di lokasi pelanggan tidak boleh terjebak gara-gara GenieACS
        // sedang bermasalah sesaat. Hanya dicoba kalau binding di atas
        // genuinely menghasilkan CpeDevice (null kalau binding sendiri
        // gagal/exception, tidak ada device untuk di-push).
        if ($cpeDevice !== null) {
            try {
                $this->wanConfigPush->push($cpeDevice, null);
            } catch (Throwable $e) {
                Log::warning("WorkOrderService: Push Konfig gagal untuk CpeDevice #{$cpeDevice->id} (work order #{$workOrder->id}) — {$e->getMessage()}");
            }
        }

        return $workOrder->fresh();
    }

    /**
     * v0.12.7 Langkah 3 — mengirim OTP WhatsApp ke NOMOR TEKNISI SENDIRI
     * (bukan pelanggan) untuk mengonfirmasi instalasi yang baru saja dia
     * kerjakan. Scope OTP diikat ke id work order ini spesifik (lihat
     * confirmationScope()) — kode untuk WO #A tidak bisa dipakai untuk WO
     * #B.
     *
     * @throws TechnicianOtpException saat rate-limited atau template WA belum di-seed
     */
    public function requestConfirmation(WorkOrder $workOrder, Technician $technician): void
    {
        $this->technicianOtp->issue(
            $technician,
            $this->confirmationScope($workOrder),
            "mengonfirmasi instalasi selesai untuk pelanggan {$workOrder->customer->name}",
            $workOrder->customer,
        );
    }

    /**
     * @throws TechnicianOtpException saat kode salah/kedaluwarsa/percobaan habis
     */
    public function confirmByTechnician(WorkOrder $workOrder, Technician $technician, string $code): WorkOrder
    {
        $this->technicianOtp->verify($technician, $this->confirmationScope($workOrder), $code);

        $workOrder->update(['technician_confirmed_at' => now()]);

        return $workOrder->fresh();
    }

    private function confirmationScope(WorkOrder $workOrder): string
    {
        return "wo-confirm:{$workOrder->id}";
    }

    /**
     * Releases the reserved/used port back to available — a cancelled
     * installation shouldn't permanently lock up ODP capacity.
     */
    public function cancel(WorkOrder $workOrder): WorkOrder
    {
        $port = $workOrder->odpPort;

        $this->transition($workOrder, WorkOrderStatus::Cancelled);

        if ($port !== null && in_array($port->status, [OdpPortStatus::Reserved, OdpPortStatus::Used], true)) {
            $port->update(['status' => OdpPortStatus::Available]);
        }

        return $workOrder->fresh();
    }

    /**
     * v0.12.7 — $modemTypeId opsional (teknisi mungkin tidak selalu tahu/
     * isi ini saat scan) — disimpan ke work_order_devices.modem_type_id
     * (kolom sudah ada sejak v0.12.4). Belum menyentuh cpe_devices sama
     * sekali di sini — itu tugas CpeBindingService::bindFromWorkOrder()
     * saat WO di-complete (lihat method itu sendiri).
     */
    public function addDevice(WorkOrder $workOrder, WorkOrderDeviceType $deviceType, string $macAddress, string $serialNumber, ?int $modemTypeId = null): WorkOrderDevice
    {
        return $workOrder->devices()->create([
            'device_type' => $deviceType,
            'mac_address' => $macAddress,
            'serial_number' => $serialNumber,
            'modem_type_id' => $modemTypeId,
            'scanned_at' => now(),
        ]);
    }

    /**
     * v0.7.5 — records the technician-relayed SSID/WiFi password on the
     * scanned device row, later pushed to the real CPE by
     * App\Services\Network\CpeBindingService::provisionWifiIfPending() once
     * this device is actually known to GenieACS. $data only ever contains
     * whichever of `ssid`/`wifi_password` the caller actually sent
     * (ProvisionWorkOrderDeviceRequest's `sometimes` rules + `validated()`
     * already filter this) — a genuine partial update, never clobbers an
     * already-recorded field with null just because this call didn't
     * resupply it.
     *
     * @param  array<string, mixed>  $data
     */
    public function provisionDeviceWifi(WorkOrder $workOrder, WorkOrderDevice $device, array $data): WorkOrderDevice
    {
        abort_unless($device->work_order_id === $workOrder->id, 404);

        $device->update($data);

        return $device->fresh();
    }

    private function assertReadyToComplete(WorkOrder $workOrder): void
    {
        $existingTypes = $workOrder->photos()->pluck('type')->map(fn (WorkOrderPhotoType $type) => $type->value)->unique();
        $requiredTypes = collect(WorkOrderPhotoType::cases())->map(fn (WorkOrderPhotoType $type) => $type->value);

        if ($requiredTypes->diff($existingTypes)->isNotEmpty()) {
            throw new IncompleteWorkOrderException(
                'Foto belum lengkap — dibutuhkan 4 jenis: '.$requiredTypes->implode(', ').'.'
            );
        }

        if ($workOrder->devices()->count() < 1) {
            throw new IncompleteWorkOrderException(
                'Minimal 1 perangkat (device) harus dicatat sebelum work order bisa diselesaikan.'
            );
        }
    }

    private function transition(WorkOrder $workOrder, WorkOrderStatus $target): void
    {
        if (! $workOrder->status->canTransitionTo($target)) {
            throw new InvalidWorkOrderStatusTransitionException($workOrder->status, $target);
        }

        $workOrder->update(['status' => $target]);
    }
}
