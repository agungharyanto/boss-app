<?php

namespace Tests\Feature\Network;

use App\Enums\CpeDeviceStatus;
use App\Enums\Tr069Root;
use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderPhotoType;
use App\Enums\WorkOrderStatus;
use App\Models\CpeActionLog;
use App\Models\CpeDevice;
use App\Models\CpeParameterMap;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use App\Models\WorkOrderPhoto;
use App\Services\Installation\WorkOrderService;
use App\Services\Network\CpeBindingService;
use App\Services\Network\GenieAcsClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CpeBindingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function readyWorkOrder(string $serialNumber): WorkOrder
    {
        $workOrder = WorkOrder::factory()->inProgress()->create();

        foreach (WorkOrderPhotoType::cases() as $type) {
            WorkOrderPhoto::factory()->forWorkOrder($workOrder)->ofType($type)->create();
        }

        WorkOrderDevice::factory()->forWorkOrder($workOrder)->create([
            'device_type' => WorkOrderDeviceType::Ont,
            'serial_number' => $serialNumber,
        ]);

        return $workOrder->fresh();
    }

    /**
     * Shape confirmed for real (not assumed) — see GenieAcsClientService's
     * own docblock for how this was verified against a live genieacs-cwmp/
     * genieacs-nbi.
     */
    private function fakeGenieAcsDevice(string $serialNumber, string $genieAcsId): array
    {
        return [
            '_id' => $genieAcsId,
            '_deviceId' => [
                '_Manufacturer' => 'Huawei',
                '_OUI' => 'AABBCC',
                '_ProductClass' => 'ONT',
                '_SerialNumber' => $serialNumber,
            ],
            '_lastInform' => now()->toIso8601String(),
            'InternetGatewayDevice' => [
                'DeviceInfo' => [
                    'ModelName' => ['_value' => 'HG8245H'],
                ],
            ],
        ];
    }

    public function test_binding_is_triggered_automatically_when_work_order_completes(): void
    {
        Http::fake([
            '*genieacs-nbi*' => Http::response([$this->fakeGenieAcsDevice('SNTEST001', 'AABBCC-ONT-SNTEST001')], 200),
        ]);

        $workOrder = $this->readyWorkOrder('SNTEST001');
        $device = $workOrder->devices()->first();

        $result = app(WorkOrderService::class)->complete($workOrder);

        $this->assertSame(WorkOrderStatus::Completed, $result->status);
        $this->assertDatabaseHas('cpe_devices', [
            'work_order_device_id' => $device->id,
            'customer_id' => $workOrder->customer_id,
            'genieacs_device_id' => 'AABBCC-ONT-SNTEST001',
            'manufacturer' => 'Huawei',
            'model_name' => 'HG8245H',
            'serial_number' => 'SNTEST001',
            'tr069_root' => 'InternetGatewayDevice',
            'status' => 'online',
        ]);
    }

    public function test_device_not_yet_seen_by_genieacs_does_not_fail_hard(): void
    {
        Http::fake(['*genieacs-nbi*' => Http::response([], 200)]);

        $workOrder = $this->readyWorkOrder('SNNOTFOUNDYET');

        $result = app(WorkOrderService::class)->complete($workOrder);

        // Completing the work order itself must never fail just because
        // GenieACS hasn't heard from this device yet.
        $this->assertSame(WorkOrderStatus::Completed, $result->status);
        $this->assertDatabaseHas('cpe_devices', [
            'serial_number' => 'SNNOTFOUNDYET',
            'genieacs_device_id' => null,
            'status' => 'pending_first_connect',
        ]);
    }

    public function test_reconcile_pending_updates_status_once_device_appears_in_genieacs(): void
    {
        $cpeDevice = CpeDevice::factory()->pendingFirstConnect()->create(['serial_number' => 'SNRECON001']);

        Http::fake([
            '*genieacs-nbi*' => Http::response([$this->fakeGenieAcsDevice('SNRECON001', 'AABBCC-ONT-SNRECON001')], 200),
        ]);

        $reconciled = app(CpeBindingService::class)->reconcilePending();

        $this->assertSame(1, $reconciled);
        $cpeDevice->refresh();
        $this->assertSame(CpeDeviceStatus::Online, $cpeDevice->status);
        $this->assertSame('AABBCC-ONT-SNRECON001', $cpeDevice->genieacs_device_id);
    }

    public function test_reconcile_pending_leaves_still_unseen_devices_untouched(): void
    {
        $cpeDevice = CpeDevice::factory()->pendingFirstConnect()->create(['serial_number' => 'SNSTILLGONE']);

        Http::fake(['*genieacs-nbi*' => Http::response([], 200)]);

        $reconciled = app(CpeBindingService::class)->reconcilePending();

        $this->assertSame(0, $reconciled);
        $this->assertSame(CpeDeviceStatus::PendingFirstConnect, $cpeDevice->fresh()->status);
    }

    private function fakeWifiParameterMaps(string $oui = 'AABBCC', string $productClass = 'ONT'): void
    {
        CpeParameterMap::factory()->create([
            'oui' => $oui,
            'product_class' => $productClass,
            'parameter_key' => 'wifi_ssid',
            'parameter_path' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
        ]);
        CpeParameterMap::factory()->create([
            'oui' => $oui,
            'product_class' => $productClass,
            'parameter_key' => 'wifi_password',
            'parameter_path' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
        ]);
    }

    /**
     * v0.7.5 — binding time: the work order device already carries
     * technician-relayed ssid/wifi_password, AND GenieACS already knows the
     * device (so bindFromWorkOrder() lands it Online in one go) — the push
     * must happen right there, not wait for reconciliation.
     */
    public function test_binding_provisions_wifi_when_device_is_immediately_online_and_credentials_recorded(): void
    {
        $this->fakeWifiParameterMaps();
        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-wo-1'], 202),
            '*genieacs-nbi*' => Http::response([$this->fakeGenieAcsDevice('SNWIFI001', 'AABBCC-ONT-SNWIFI001')], 200),
        ]);

        $workOrder = WorkOrder::factory()->inProgress()->create();
        foreach (WorkOrderPhotoType::cases() as $type) {
            WorkOrderPhoto::factory()->forWorkOrder($workOrder)->ofType($type)->create();
        }
        WorkOrderDevice::factory()->forWorkOrder($workOrder)->withWifiCredentials('RumahBaru', 'password123')->create([
            'device_type' => WorkOrderDeviceType::Ont,
            'serial_number' => 'SNWIFI001',
        ]);

        app(WorkOrderService::class)->complete($workOrder->fresh());

        $cpeDevice = CpeDevice::where('serial_number', 'SNWIFI001')->firstOrFail();
        $this->assertNotNull($cpeDevice->wifi_provisioned_at);

        $this->assertDatabaseHas('cpe_action_logs', [
            'cpe_device_id' => $cpeDevice->id,
            'status' => 'delivered',
            'performed_by' => null,
        ]);
        $log = CpeActionLog::where('cpe_device_id', $cpeDevice->id)->firstOrFail();
        $this->assertSame('auto_provisioning_binding', $log->parameters['triggered_by']);
        $this->assertSame('RumahBaru', $log->parameters['new_ssid']);
    }

    /**
     * The mirror case: device NOT yet known to GenieACS at binding time
     * (stays pending_first_connect, no provisioning attempt yet), THEN
     * reconcilePending() matches it later — that's where the push must
     * happen, tagged with the reconciliation trigger label, not the binding
     * one.
     */
    public function test_reconcile_pending_provisions_wifi_once_matched(): void
    {
        $this->fakeWifiParameterMaps();
        $foundDevice = $this->fakeGenieAcsDevice('SNWIFISLOW', 'AABBCC-ONT-SNWIFISLOW');

        // Http::fake() called ONCE for the whole test, on purpose — a
        // second Http::fake() call mid-test was found to leave the OLDER
        // stub still matching first for genieacs-nbi:7557/devices*, so the
        // "not found yet" response from binding time kept intercepting the
        // reconcile-time GET too. A response sequence lets the SAME URL
        // pattern answer differently across the two calls this test makes
        // to it: not-found during bindFromWorkOrder(), found during
        // reconcilePending() (once for findByStoredSerial, once more for
        // CpeActionService's own independent findDeviceById() lookup).
        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-wo-2'], 202),
            // Anchored to `?query=` specifically (not a bare `devices*`
            // wildcard) — found necessary: a broad `devices*` pattern also
            // matches the /tasks POST url above
            // (`devices/{id}/tasks?connection_request`), silently stealing
            // from this sequence and exhausting it early. 4 GET calls total
            // across the whole test: bindFromWorkOrder()'s findByStoredSerial
            // (not found yet), then at reconcile time — findByStoredSerial,
            // attributesFromGenieAcsDevice()'s own getStandardIdentity() (a
            // second, independent findDeviceById call), and
            // CpeActionService::resolveOuiProductClass()'s own
            // findDeviceById — all three "found" once GenieACS knows it.
            'genieacs-nbi:7557/devices?query=*' => Http::sequence()
                ->push([], 200)
                ->push([$foundDevice], 200)
                ->push([$foundDevice], 200)
                ->push([$foundDevice], 200),
        ]);

        $workOrder = WorkOrder::factory()->inProgress()->create();
        foreach (WorkOrderPhotoType::cases() as $type) {
            WorkOrderPhoto::factory()->forWorkOrder($workOrder)->ofType($type)->create();
        }
        WorkOrderDevice::factory()->forWorkOrder($workOrder)->withWifiCredentials('RumahLambat', 'password456')->create([
            'device_type' => WorkOrderDeviceType::Ont,
            'serial_number' => 'SNWIFISLOW',
        ]);

        app(WorkOrderService::class)->complete($workOrder->fresh());

        $cpeDevice = CpeDevice::where('serial_number', 'SNWIFISLOW')->firstOrFail();
        $this->assertSame(CpeDeviceStatus::PendingFirstConnect, $cpeDevice->status);
        $this->assertNull($cpeDevice->wifi_provisioned_at);
        // Nothing attempted yet — no cpe_action_logs row at all.
        $this->assertDatabaseCount('cpe_action_logs', 0);

        app(CpeBindingService::class)->reconcilePending();

        $cpeDevice->refresh();
        $this->assertNotNull($cpeDevice->wifi_provisioned_at);
        $log = CpeActionLog::where('cpe_device_id', $cpeDevice->id)->firstOrFail();
        $this->assertSame('auto_provisioning_reconciliation', $log->parameters['triggered_by']);
    }

    public function test_binding_does_not_attempt_provisioning_when_no_wifi_credentials_recorded(): void
    {
        Http::fake([
            '*genieacs-nbi*' => Http::response([$this->fakeGenieAcsDevice('SNNOCRED001', 'AABBCC-ONT-SNNOCRED001')], 200),
        ]);

        $workOrder = $this->readyWorkOrder('SNNOCRED001');

        app(WorkOrderService::class)->complete($workOrder);

        $cpeDevice = CpeDevice::where('serial_number', 'SNNOCRED001')->firstOrFail();
        $this->assertNull($cpeDevice->wifi_provisioned_at);
        $this->assertDatabaseCount('cpe_action_logs', 0);
    }

    public function test_binding_does_not_reprovision_a_device_that_was_already_provisioned(): void
    {
        $this->fakeWifiParameterMaps();
        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-wo-3'], 202),
            '*genieacs-nbi*' => Http::response([$this->fakeGenieAcsDevice('SNDUPCHECK', 'AABBCC-ONT-SNDUPCHECK')], 200),
        ]);

        $workOrder = WorkOrder::factory()->inProgress()->create();
        $workOrderDevice = WorkOrderDevice::factory()->forWorkOrder($workOrder)->withWifiCredentials()->create([
            'device_type' => WorkOrderDeviceType::Ont,
            'serial_number' => 'SNDUPCHECK',
        ]);

        // Call the binding service directly twice — same real-world race
        // this guard exists for (bindFromWorkOrder() at completion time,
        // reconcilePending() on its own 5-minute cycle, could both reach a
        // freshly-online device depending on timing).
        app(CpeBindingService::class)->bindFromWorkOrder($workOrder->fresh());
        app(CpeBindingService::class)->bindFromWorkOrder($workOrder->fresh());

        $this->assertDatabaseCount('cpe_action_logs', 1);
    }

    public function test_binding_leaves_wifi_provisioned_at_null_when_the_push_itself_fails(): void
    {
        // No CpeParameterMap rows at all — setWifiCredentials() will fail
        // to resolve a path, landing the CpeActionLog as `failed`.
        Http::fake([
            '*genieacs-nbi*' => Http::response([$this->fakeGenieAcsDevice('SNPUSHFAIL', 'AABBCC-ONT-SNPUSHFAIL')], 200),
        ]);

        $workOrder = WorkOrder::factory()->inProgress()->create();
        foreach (WorkOrderPhotoType::cases() as $type) {
            WorkOrderPhoto::factory()->forWorkOrder($workOrder)->ofType($type)->create();
        }
        WorkOrderDevice::factory()->forWorkOrder($workOrder)->withWifiCredentials()->create([
            'device_type' => WorkOrderDeviceType::Ont,
            'serial_number' => 'SNPUSHFAIL',
        ]);

        // Binding itself must still succeed even though the wifi push fails
        // (best-effort, per WorkOrderService::complete()'s own posture).
        $result = app(WorkOrderService::class)->complete($workOrder->fresh());
        $this->assertSame(WorkOrderStatus::Completed, $result->status);

        $cpeDevice = CpeDevice::where('serial_number', 'SNPUSHFAIL')->firstOrFail();
        $this->assertNull($cpeDevice->wifi_provisioned_at);
        $this->assertDatabaseHas('cpe_action_logs', [
            'cpe_device_id' => $cpeDevice->id,
            'status' => 'failed',
        ]);
    }

    public function test_get_standard_identity_falls_back_from_tr098_to_tr181_root(): void
    {
        Http::fake([
            '*genieacs-nbi*' => Http::response([[
                '_id' => 'X',
                '_deviceId' => [
                    '_Manufacturer' => 'ZTE',
                    '_OUI' => '112233',
                    '_ProductClass' => 'ONT',
                    '_SerialNumber' => 'SNTR181',
                ],
                // No InternetGatewayDevice key at all — a TR-181-only device.
                'Device' => [
                    'DeviceInfo' => [
                        'ModelName' => ['_value' => 'F670L'],
                    ],
                ],
            ]], 200),
        ]);

        $identity = app(GenieAcsClientService::class)->getStandardIdentity('X');

        $this->assertSame('ZTE', $identity['manufacturer']);
        $this->assertSame('F670L', $identity['model_name']);
        $this->assertSame('SNTR181', $identity['serial_number']);
        $this->assertSame(Tr069Root::Device, $identity['tr069_root']);
    }
}
