<?php

namespace App\Services\Installation;

use App\Enums\OdpPortStatus;
use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderPhotoType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\IncompleteWorkOrderException;
use App\Exceptions\InvalidWorkOrderStatusTransitionException;
use App\Models\OdpPort;
use App\Models\Subscription;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use App\Services\Network\CpeBindingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkOrderService
{
    public function __construct(
        private readonly OdpLocatorService $odpLocator,
        private readonly CpeBindingService $cpeBinding,
    ) {}

    /**
     * pending_odp_check -> (pending_verification + port reserved) or
     * odp_unavailable, depending on whether OdpLocatorService finds a free
     * port. The port is re-checked under a row lock right before reserving
     * it (not just trusted from the locate query) to avoid two work orders
     * racing for the same port between locate and reserve.
     */
    public function createFromSubscription(Subscription $subscription): WorkOrder
    {
        return DB::transaction(function () use ($subscription) {
            $workOrder = WorkOrder::create([
                'tenant_id' => $subscription->tenant_id,
                'reseller_id' => $subscription->reseller_id,
                'subscription_id' => $subscription->id,
                'customer_id' => $subscription->customer_id,
                'status' => WorkOrderStatus::PendingOdpCheck,
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

    public function assignTechnician(WorkOrder $workOrder, Technician $technician): WorkOrder
    {
        $this->transition($workOrder, WorkOrderStatus::Assigned);
        $workOrder->update(['technician_id' => $technician->id]);

        return $workOrder->fresh();
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
     * scanned device.
     */
    public function complete(WorkOrder $workOrder): WorkOrder
    {
        if (! $workOrder->status->canTransitionTo(WorkOrderStatus::Completed)) {
            throw new InvalidWorkOrderStatusTransitionException($workOrder->status, WorkOrderStatus::Completed);
        }

        $this->assertReadyToComplete($workOrder);

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
        try {
            $this->cpeBinding->bindFromWorkOrder($workOrder);
        } catch (Throwable $e) {
            Log::warning("WorkOrderService: CPE binding failed for work order #{$workOrder->id} — {$e->getMessage()}");
        }

        return $workOrder->fresh();
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

    public function addDevice(WorkOrder $workOrder, WorkOrderDeviceType $deviceType, string $macAddress, string $serialNumber): WorkOrderDevice
    {
        return $workOrder->devices()->create([
            'device_type' => $deviceType,
            'mac_address' => $macAddress,
            'serial_number' => $serialNumber,
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
