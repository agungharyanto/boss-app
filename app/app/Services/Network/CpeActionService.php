<?php

namespace App\Services\Network;

use App\Enums\CpeActionStatus;
use App\Enums\CpeActionType;
use App\Models\CpeActionLog;
use App\Models\CpeDevice;
use App\Models\CpeParameterMap;
use App\Models\User;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Remote CPE actions (reboot, WiFi credential change) — v0.7.4. Deliberately
 * built to be correct in "not instant" mode first, per Agung's explicit
 * planning decision: v0.7.3 (Connection Request routing, the mechanism that
 * would make an action apply to the device right away) is implemented but
 * its end-to-end success has NOT been confirmed against real hardware yet
 * (see CLAUDE.md "GenieACS Connection Request Routing (v0.7.3)"). So every
 * method here:
 *   1. Writes a CpeActionLog row FIRST (status `queued`) — the audit trail
 *      exists even if everything after this fails.
 *   2. Still PASSES `connection_request=true` through to GenieACS (via
 *      GenieAcsClientService::sendTask()'s default) — harmless if it fails,
 *      free instant delivery if v0.7.3 turns out to already work for a given
 *      device.
 *   3. Never treats the connection_request's own success/failure as
 *      pass/fail for the ACTION itself — status only ever reflects whether
 *      the task was successfully ENQUEUED on genieacs-nbi (`delivered`) or
 *      not (`failed`). A `delivered` row is NOT proof the device executed
 *      anything — callers (API responses, Livewire UI) must say so honestly
 *      ("perintah terkirim, diterapkan saat device connect berikutnya"),
 *      never "berhasil" / "done".
 */
class CpeActionService
{
    public function __construct(
        private readonly GenieAcsClientService $genieAcsClient,
    ) {}

    public function reboot(CpeDevice $device, User $actor): CpeActionLog
    {
        $log = $this->createLog($device, $actor, CpeActionType::Reboot, null);

        $this->attemptDelivery($log, $device, fn () => ['name' => 'reboot']);

        return $log->fresh();
    }

    /**
     * $ssid/$password are each independently optional — at least one must
     * be given (enforced here as a defensive guard; the primary validation
     * lives in App\Http\Requests\Network\SetCpeWifiCredentialsRequest, this
     * is for any other caller). When both are given, one CpeActionLog row is
     * still written (this method's own return type is a single
     * CpeActionLog, not a collection) with action_type leaning toward
     * SetSsid — the coarse label picks SSID when both changed together
     * since it's the more customer-visible identity change, but `parameters`
     * always records exactly which field(s) actually changed, so the audit
     * trail itself is never lossy even when the label is a simplification.
     * Both fields are sent as ONE GenieACS setParameterValues task (not two
     * separate tasks) — applies atomically on the device's next Inform
     * rather than risking the two fields landing across two different
     * Inform cycles.
     *
     * $actor is nullable (v0.7.5) — App\Services\Network\CpeBindingService's
     * auto-provisioning hook (binding/reconciliation) has no human actor at
     * all, confirmed with Agung: nullable + "Sistem (auto-provisioning)" in
     * the UI is more honest than a fake system user. $triggeredBy is a free
     * label recorded in `parameters['triggered_by']` for exactly that case
     * (`auto_provisioning_binding`/`auto_provisioning_reconciliation`) —
     * null for a real UI/API-triggered call, where the actor itself already
     * answers "who did this".
     */
    public function setWifiCredentials(CpeDevice $device, ?string $ssid, ?string $password, ?User $actor, ?string $triggeredBy = null): CpeActionLog
    {
        if ($ssid === null && $password === null) {
            throw new InvalidArgumentException('Minimal salah satu dari ssid/password harus diisi.');
        }

        $parameters = [];

        if ($ssid !== null) {
            $parameters['new_ssid'] = $ssid;
        }

        if ($password !== null) {
            // Never the plaintext password — this is an audit fingerprint
            // ("did this change to the same value as an earlier entry?"),
            // not a retrievable credential. Deliberately unsalted (a salt
            // would defeat that exact comparison use case) — the real
            // credential lives only on the device/GenieACS, never in this
            // table, so this isn't standing in for a login-credential hash.
            $parameters['password_changed'] = true;
            $parameters['new_password_fingerprint'] = hash('sha256', $password);
        }

        if ($triggeredBy !== null) {
            $parameters['triggered_by'] = $triggeredBy;
        }

        $actionType = $ssid !== null ? CpeActionType::SetSsid : CpeActionType::SetPassword;
        $log = $this->createLog($device, $actor, $actionType, $parameters);

        $this->attemptDelivery($log, $device, function () use ($device, $ssid, $password) {
            [$oui, $productClass] = $this->resolveOuiProductClass($device);

            $parameterValues = [];

            if ($ssid !== null) {
                $parameterValues[] = [$this->requirePath($oui, $productClass, 'wifi_ssid'), $ssid, 'xsd:string'];
            }

            if ($password !== null) {
                $parameterValues[] = [$this->requirePath($oui, $productClass, 'wifi_password'), $password, 'xsd:string'];
            }

            return ['name' => 'setParameterValues', 'parameterValues' => $parameterValues];
        });

        return $log->fresh();
    }

    private function createLog(CpeDevice $device, ?User $actor, CpeActionType $actionType, ?array $parameters): CpeActionLog
    {
        return CpeActionLog::create([
            'cpe_device_id' => $device->id,
            'tenant_id' => $device->tenant_id,
            'reseller_id' => $device->reseller_id,
            'performed_by' => $actor?->id,
            'action_type' => $actionType,
            'parameters' => $parameters,
            'status' => CpeActionStatus::Queued,
        ]);
    }

    /**
     * $buildPayload is evaluated INSIDE the try block on purpose — for
     * setWifiCredentials() this is where parameter-map resolution happens,
     * and a missing mapping must land as a `failed` log entry (with a clear
     * reason) exactly like a GenieACS enqueue failure, not an uncaught
     * exception that skips writing anything.
     */
    private function attemptDelivery(CpeActionLog $log, CpeDevice $device, Closure $buildPayload): void
    {
        try {
            if ($device->genieacs_device_id === null) {
                throw new RuntimeException('Device belum pernah terhubung ke GenieACS (genieacs_device_id kosong) — tidak bisa mengirim task.');
            }

            $taskPayload = $buildPayload();

            $result = $this->genieAcsClient->sendTask($device->genieacs_device_id, $taskPayload);

            $log->update([
                'genieacs_task_id' => $result['task_id'],
                'status' => CpeActionStatus::Delivered,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $log->update([
                'status' => CpeActionStatus::Failed,
                'failed_reason' => substr($e->getMessage(), 0, 500),
                'completed_at' => now(),
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string} [oui, productClass]
     */
    private function resolveOuiProductClass(CpeDevice $device): array
    {
        $genieAcsDevice = $this->genieAcsClient->findDeviceById($device->genieacs_device_id);

        if ($genieAcsDevice === null) {
            throw new RuntimeException('Device tidak ditemukan di GenieACS (genieacs_device_id sudah tidak valid).');
        }

        $oui = $genieAcsDevice['_deviceId']['_OUI'] ?? null;
        $productClass = $genieAcsDevice['_deviceId']['_ProductClass'] ?? null;

        if ($oui === null || $productClass === null) {
            throw new RuntimeException('_deviceId._OUI/_ProductClass tidak lengkap di data GenieACS untuk device ini.');
        }

        return [$oui, $productClass];
    }

    private function requirePath(string $oui, string $productClass, string $parameterKey): string
    {
        $path = CpeParameterMap::query()
            ->where('oui', $oui)
            ->where('product_class', $productClass)
            ->where('parameter_key', $parameterKey)
            ->value('parameter_path');

        if ($path === null) {
            throw new RuntimeException("Parameter mapping '{$parameterKey}' belum ada di cpe_parameter_maps untuk OUI={$oui}/ProductClass={$productClass}.");
        }

        return $path;
    }
}
