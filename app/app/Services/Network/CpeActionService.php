<?php

namespace App\Services\Network;

use App\Enums\CpeActionStatus;
use App\Enums\CpeActionType;
use App\Models\CpeActionLog;
use App\Models\CpeDevice;
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
     *
     * $ssidIndex (2026-08-17, per-SSID "Ganti WiFi" on the CPE detail page)
     * — which WLANConfiguration instance this targets, defaults to 1 (the
     * primary SSID, matching every existing caller's prior hardcoded
     * behavior: the API v1 endpoint and CpeBindingService's auto-provisioning
     * hook both only ever meant "the main SSID", never asked about others).
     * Builds the TR-069 path directly (InternetGatewayDevice.LANDevice.1.
     * WLANConfiguration.{ssidIndex}.SSID/KeyPassphrase) instead of going
     * through the `cpe_parameter_maps` catalog the way rx_power_dbm/
     * tx_power_dbm still do — same reasoning already established for MAC/
     * PPPoE resolution (see CpeParameterResolverService): this is a fixed,
     * standard TR-069 object confirmed identical across every vendor OUI in
     * this fleet during the multi-WAN/multi-SSID discovery work, not a
     * vendor-specific quantity needing a per-OUI catalog row. The one
     * pre-existing catalog row for this (F86CE1/F663NV3a, hardcoded to
     * WLANConfiguration.1) is now unused dead data, not a fallback — this
     * method no longer consults `cpe_parameter_maps` at all.
     */
    public function setWifiCredentials(CpeDevice $device, ?string $ssid, ?string $password, ?User $actor, int $ssidIndex = 1, ?string $triggeredBy = null): CpeActionLog
    {
        if ($ssid === null && $password === null) {
            throw new InvalidArgumentException('Minimal salah satu dari ssid/password harus diisi.');
        }

        if ($ssidIndex < 1) {
            throw new InvalidArgumentException('ssidIndex harus >= 1.');
        }

        $parameters = ['ssid_index' => $ssidIndex];

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

        $this->attemptDelivery($log, $device, function () use ($ssid, $password, $ssidIndex) {
            $parameterValues = [];

            if ($ssid !== null) {
                $parameterValues[] = [$this->wlanConfigurationPath($ssidIndex, 'SSID'), $ssid, 'xsd:string'];
            }

            if ($password !== null) {
                $parameterValues[] = [$this->wlanConfigurationPath($ssidIndex, 'KeyPassphrase'), $password, 'xsd:string'];
            }

            return ['name' => 'setParameterValues', 'parameterValues' => $parameterValues];
        });

        return $log->fresh();
    }

    /**
     * Enable/disable a single SSID (2026-08-17) — same fixed-path reasoning
     * as setWifiCredentials() above, pushing a single boolean leaf. Never
     * throws for an "already in that state" call — GenieACS/the device
     * itself is the source of truth for the CURRENT value, this method
     * always just sends the requested one; UI-level confirmation (this is
     * the actual guard against an accidental disable) lives entirely in
     * the caller, not here.
     */
    public function setSsidEnabled(CpeDevice $device, int $ssidIndex, bool $enabled, ?User $actor): CpeActionLog
    {
        if ($ssidIndex < 1) {
            throw new InvalidArgumentException('ssidIndex harus >= 1.');
        }

        $log = $this->createLog($device, $actor, CpeActionType::SetSsidEnabled, [
            'ssid_index' => $ssidIndex,
            'enabled' => $enabled,
        ]);

        $this->attemptDelivery($log, $device, fn () => [
            'name' => 'setParameterValues',
            'parameterValues' => [[$this->wlanConfigurationPath($ssidIndex, 'Enable'), $enabled, 'xsd:boolean']],
        ]);

        return $log->fresh();
    }

    private function wlanConfigurationPath(int $ssidIndex, string $leaf): string
    {
        return sprintf('InternetGatewayDevice.LANDevice.1.WLANConfiguration.%d.%s', $ssidIndex, $leaf);
    }

    /**
     * "Sync Sekarang" (2026-08-19) — an on-demand nudge to re-discover this
     * device's own WAN/LAN parameter subtree right now, instead of waiting
     * for its own periodic Inform to naturally re-sync (the same
     * `refreshObject` mechanism used manually, over and over, throughout
     * this whole GenieACS investigation — this button is that same manual
     * tinker step turned into a real UI feature). Two SEPARATE tasks
     * (WANDevice covers RX/TX/MAC/PPPoE; LANDevice covers WiFi/SSID/
     * connected hosts) rather than one root-level refresh — a full root
     * refreshObject risks GenieACS's own `too_many_commits` fault on a
     * device with a large tree (seen for real during the RX Power discovery
     * work), and neither WAN nor LAN alone covers everything this page
     * shows.
     *
     * Deliberately NOT built on attemptDelivery() — that helper assumes
     * exactly one task per log entry (one genieacs_task_id column). Success
     * here means "at least one of the two enqueued", matching this
     * feature's own framing as a best-effort nudge, not an atomic
     * operation — a partial sync (e.g. WAN refreshed but LAN's own enqueue
     * hit a transient GenieACS error) is still more useful than nothing.
     */
    public function syncNow(CpeDevice $device, ?User $actor): CpeActionLog
    {
        $log = $this->createLog($device, $actor, CpeActionType::SyncNow, null);

        if ($device->genieacs_device_id === null) {
            $log->update([
                'status' => CpeActionStatus::Failed,
                'failed_reason' => 'Device belum pernah terhubung ke GenieACS (genieacs_device_id kosong) — tidak bisa mengirim task.',
                'completed_at' => now(),
            ]);

            return $log->fresh();
        }

        $targets = [
            'wan' => 'InternetGatewayDevice.WANDevice',
            'lan' => 'InternetGatewayDevice.LANDevice',
        ];
        $taskIds = [];
        $errors = [];

        foreach ($targets as $label => $objectName) {
            try {
                $result = $this->genieAcsClient->sendTask($device->genieacs_device_id, [
                    'name' => 'refreshObject',
                    'objectName' => $objectName,
                ]);
                $taskIds[$label] = $result['task_id'];
            } catch (Throwable $e) {
                $errors[$label] = substr($e->getMessage(), 0, 200);
            }
        }

        if ($taskIds === []) {
            $log->update([
                'status' => CpeActionStatus::Failed,
                'failed_reason' => 'Semua refreshObject task gagal dikirim: '.json_encode($errors),
                'parameters' => ['errors' => $errors],
                'completed_at' => now(),
            ]);
        } else {
            $log->update([
                'status' => CpeActionStatus::Delivered,
                'genieacs_task_id' => implode(',', $taskIds),
                'parameters' => array_filter(['task_ids' => $taskIds, 'errors' => $errors !== [] ? $errors : null]),
                'completed_at' => now(),
            ]);
        }

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
}
