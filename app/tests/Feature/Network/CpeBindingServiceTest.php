<?php

namespace Tests\Feature\Network;

use App\Enums\CpeDeviceStatus;
use App\Enums\Tr069Root;
use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderPhotoType;
use App\Enums\WorkOrderStatus;
use App\Models\CpeDevice;
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
