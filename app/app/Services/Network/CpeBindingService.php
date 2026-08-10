<?php

namespace App\Services\Network;

use App\Enums\CpeActionStatus;
use App\Enums\CpeDeviceStatus;
use App\Enums\WorkOrderDeviceType;
use App\Exceptions\CpeBindingException;
use App\Models\CpeDevice;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a completed WorkOrder's scanned device(s) into a `cpe_devices` row —
 * the ONLY way a CpeDevice ever gets created (no manual-create endpoint,
 * per the locked decision "binding otomatis dari Installation, bukan input
 * admin").
 *
 * **Which of a work order's (possibly several) scanned devices gets
 * bound**: prefers a `WorkOrderDeviceType::Ont` row if one exists, else
 * falls back to whichever device was scanned first. This wasn't explicit in
 * the original spec (`bindFromWorkOrder(): CpeDevice` returns a single row,
 * but a work order can have >1 scanned device — e.g. ONT + router) — ONT is
 * preferred because in the typical ISP topology it's the device that
 * actually dials GenieACS; a customer's own router/AP behind it often isn't
 * TR-069-managed at all. Flagged as a judgment call, not a confirmed
 * requirement — revisit if a work order ever needs more than one CPE bound
 * per installation.
 *
 * **Matching key is serial number, not MAC address**, despite the original
 * spec text calling MAC "biasanya paling reliable" — confirmed empirically
 * (real TR-069 Inform sent to a live genieacs-cwmp, response inspected) that
 * GenieACS's `_deviceId._SerialNumber` is populated straight from the
 * Inform RPC's own DeviceId struct, a REQUIRED field of TR-069 itself, the
 * same for every vendor. MAC address has no such standard path — it only
 * ever appears under vendor/topology-specific parameter trees (e.g.
 * WANDevice.*.WANConnectionDevice.*.WANIPConnection.*.MACAddress), and
 * mapping those per-vendor is explicitly v0.7.2 scope, not this one. Serial
 * number matching needs zero vendor-specific knowledge, so it's what v0.7.1
 * actually implements; MAC-based matching can be added once v0.7.2 lands.
 */
class CpeBindingService
{
    public function __construct(
        private readonly GenieAcsClientService $client,
        private readonly CpeActionService $cpeAction,
    ) {}

    public function bindFromWorkOrder(WorkOrder $workOrder): CpeDevice
    {
        $device = $workOrder->devices()->where('device_type', WorkOrderDeviceType::Ont)->first()
            ?? $workOrder->devices()->first();

        if ($device === null) {
            throw new CpeBindingException(
                "Work order #{$workOrder->id} tidak punya device tercatat — binding CPE butuh minimal 1 device hasil scan."
            );
        }

        $attributes = [
            'tenant_id' => $workOrder->tenant_id,
            'customer_id' => $workOrder->customer_id,
            'reseller_id' => $workOrder->reseller_id,
            'serial_number' => $device->serial_number,
            'bound_at' => now(),
        ];

        $genieAcsDevice = $this->findByStoredSerial($device->serial_number);

        // Device belum pernah inform ke GenieACS — TIDAK gagal keras (per
        // keputusan yang dikunci). ReconcileCpeDevices akan mencoba
        // mencocokkannya lagi setiap kali dijalankan.
        if ($genieAcsDevice === null) {
            $attributes['genieacs_device_id'] = null;
            $attributes['status'] = CpeDeviceStatus::PendingFirstConnect;
        } else {
            $attributes = array_merge($attributes, $this->attributesFromGenieAcsDevice($genieAcsDevice));
        }

        $cpeDevice = CpeDevice::updateOrCreate(
            ['work_order_device_id' => $device->id],
            $attributes
        );

        $this->provisionWifiIfPending($cpeDevice, 'auto_provisioning_binding');

        return $cpeDevice;
    }

    /**
     * Called periodically (see App\Console\Commands\ReconcileCpeDevices) —
     * matches any `pending_first_connect` CpeDevice against GenieACS by
     * serial number, for the common case of a device being scanned/bound
     * before its very first real TR-069 Inform. Returns how many rows were
     * reconciled, purely for logging/observability.
     */
    public function reconcilePending(): int
    {
        $pending = CpeDevice::withoutGlobalScopes()
            ->where('status', CpeDeviceStatus::PendingFirstConnect)
            ->get();

        $reconciled = 0;

        foreach ($pending as $cpeDevice) {
            $genieAcsDevice = $this->findByStoredSerial($cpeDevice->serial_number);

            if ($genieAcsDevice === null) {
                continue;
            }

            $cpeDevice->update($this->attributesFromGenieAcsDevice($genieAcsDevice));
            $this->provisionWifiIfPending($cpeDevice, 'auto_provisioning_reconciliation');
            $reconciled++;
        }

        return $reconciled;
    }

    /**
     * v0.7.5 — pushes a work order device's technician-input SSID/password
     * (`work_order_devices.ssid`/`wifi_password`, captured via the bridge
     * endpoint `PATCH /work-orders/{id}/devices/{device}/provisioning` —
     * see docs/API.md "GenieACS Auto-Provisioning (v0.7.5)") to the device
     * the moment it's actually known to GenieACS, from EITHER caller above.
     * `$triggeredBy` distinguishes which one in the resulting
     * CpeActionLog.parameters — useful when reading the history panel later,
     * not load-bearing for the guard itself.
     *
     * Best-effort — same "catch, log, never let this break the caller's own
     * job" posture as every other GenieACS side-effect hook in this codebase
     * (WhatsappGatewayService::buildAndQueue(), and this class's own call
     * from WorkOrderService::complete()). `wifi_provisioned_at` is set ONLY
     * on a genuine `delivered` result — not merely "we attempted it" — so a
     * failed attempt (e.g. no cpe_parameter_maps row for this vendor/model)
     * stays visibly null and can still be pushed by hand later via the
     * v0.7.4 "Ganti WiFi" UI/API, rather than silently pretending it's done.
     * Note there's no automatic retry path if THIS one attempt fails: a
     * CpeDevice only reaches Online once, and reconcilePending()'s own query
     * only ever looks at `pending_first_connect` rows.
     */
    private function provisionWifiIfPending(CpeDevice $cpeDevice, string $triggeredBy): void
    {
        if ($cpeDevice->genieacs_device_id === null || $cpeDevice->wifi_provisioned_at !== null) {
            return;
        }

        $workOrderDevice = $cpeDevice->workOrderDevice;

        if ($workOrderDevice === null || ($workOrderDevice->ssid === null && $workOrderDevice->wifi_password === null)) {
            return;
        }

        try {
            $log = $this->cpeAction->setWifiCredentials(
                $cpeDevice,
                $workOrderDevice->ssid,
                $workOrderDevice->wifi_password,
                null,
                $triggeredBy,
            );

            if ($log->status === CpeActionStatus::Delivered) {
                $cpeDevice->update(['wifi_provisioned_at' => now()]);
            }
        } catch (Throwable $e) {
            Log::warning("CpeBindingService: auto-provisioning WiFi gagal untuk CpeDevice #{$cpeDevice->id} — {$e->getMessage()}");
        }
    }

    private function findByStoredSerial(string $serialNumber): ?array
    {
        $matches = $this->client->queryDevices(['_deviceId._SerialNumber' => $serialNumber]);

        return $matches[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $genieAcsDevice
     * @return array<string, mixed>
     */
    private function attributesFromGenieAcsDevice(array $genieAcsDevice): array
    {
        $identity = $this->client->getStandardIdentity($genieAcsDevice['_id']);

        return [
            'genieacs_device_id' => $genieAcsDevice['_id'],
            'manufacturer' => $identity['manufacturer'],
            'model_name' => $identity['model_name'],
            'tr069_root' => $identity['tr069_root'],
            'status' => CpeDeviceStatus::Online,
            'last_inform_at' => isset($genieAcsDevice['_lastInform'])
                ? Carbon::parse($genieAcsDevice['_lastInform'])
                : null,
        ];
    }
}
