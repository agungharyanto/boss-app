<?php

namespace Tests\Feature\Installation;

use App\Enums\OdpPortStatus;
use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderPhotoType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\IncompleteWorkOrderException;
use App\Exceptions\InvalidWorkOrderStatusTransitionException;
use App\Exceptions\WorkOrderNotConfirmedException;
use App\Models\BandwidthProfile;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\ModemType;
use App\Models\NetworkProfileGroup;
use App\Models\Odp;
use App\Models\OdpPort;
use App\Models\PppPackage;
use App\Models\Subscription;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\WanConfigTemplate;
use App\Models\WorkOrderPhoto;
use App\Services\Installation\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function subscriptionWithNearbyOdp(): array
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'latitude' => -6.2000,
            'longitude' => 106.8000,
        ]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'reseller_id' => null,
        ]);
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.2001, 'longitude' => 106.8001]);
        $port = OdpPort::factory()->forOdp($odp)->create();

        return [$subscription, $port];
    }

    public function test_create_from_subscription_reserves_the_nearest_port_and_moves_to_pending_verification(): void
    {
        [$subscription, $port] = $this->subscriptionWithNearbyOdp();

        $workOrder = app(WorkOrderService::class)->createFromSubscription($subscription);

        $this->assertSame(WorkOrderStatus::PendingVerification, $workOrder->status);
        $this->assertSame($port->id, $workOrder->odp_port_id);
        $this->assertSame(OdpPortStatus::Reserved, $port->fresh()->status);
    }

    // --- v0.26.1 — wiring work_orders.scheduled_at ---

    public function test_create_from_subscription_without_scheduled_at_leaves_it_null(): void
    {
        [$subscription] = $this->subscriptionWithNearbyOdp();

        $workOrder = app(WorkOrderService::class)->createFromSubscription($subscription);

        $this->assertNull($workOrder->scheduled_at);
    }

    public function test_create_from_subscription_with_scheduled_at_persists_it(): void
    {
        [$subscription] = $this->subscriptionWithNearbyOdp();

        $workOrder = app(WorkOrderService::class)->createFromSubscription($subscription, '2026-10-01 10:00:00');

        $this->assertNotNull($workOrder->scheduled_at);
        $this->assertSame('2026-10-01 10:00:00', $workOrder->scheduled_at->format('Y-m-d H:i:s'));
    }

    // --- v0.26.2 — hook dispatchImmediately() (keputusan HYBRID, amendment docblock v0.26.1) ---

    public function test_create_from_subscription_without_scheduled_at_dispatches_immediately(): void
    {
        [$subscription] = $this->subscriptionWithNearbyOdp();

        $workOrder = app(WorkOrderService::class)->createFromSubscription($subscription);

        $this->assertNotNull($workOrder->dispatched_at);
    }

    /**
     * WO DENGAN janji spesifik TIDAK dispatch di sini — satu-satunya
     * jalur untuk itu tetap DispatchWorkOrders command (window
     * scheduled_at - dispatch_offset_minutes), sudah ditest terpisah di
     * WorkOrderDispatchServiceTest/DispatchWorkOrdersCommandTest.
     */
    public function test_create_from_subscription_with_scheduled_at_does_not_dispatch_immediately(): void
    {
        [$subscription] = $this->subscriptionWithNearbyOdp();

        $workOrder = app(WorkOrderService::class)->createFromSubscription($subscription, now()->addHours(5)->format('Y-m-d H:i:s'));

        $this->assertNull($workOrder->dispatched_at);
    }

    public function test_schedule_visit_sets_a_schedule_on_a_work_order_created_without_one(): void
    {
        [$subscription] = $this->subscriptionWithNearbyOdp();
        $workOrder = app(WorkOrderService::class)->createFromSubscription($subscription);
        $this->assertNull($workOrder->scheduled_at);

        $updated = app(WorkOrderService::class)->scheduleVisit($workOrder, '2026-10-02 14:30:00');

        $this->assertSame('2026-10-02 14:30:00', $updated->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_schedule_visit_with_null_clears_an_existing_schedule(): void
    {
        [$subscription] = $this->subscriptionWithNearbyOdp();
        $workOrder = app(WorkOrderService::class)->createFromSubscription($subscription, '2026-10-01 10:00:00');
        $this->assertNotNull($workOrder->scheduled_at);

        $updated = app(WorkOrderService::class)->scheduleVisit($workOrder, null);

        $this->assertNull($updated->scheduled_at);
    }

    public function test_create_from_subscription_marks_odp_unavailable_when_no_port_found(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'reseller_id' => null,
        ]);

        $workOrder = app(WorkOrderService::class)->createFromSubscription($subscription);

        $this->assertSame(WorkOrderStatus::OdpUnavailable, $workOrder->status);
        $this->assertNull($workOrder->odp_port_id);
    }

    public function test_illegal_transition_pending_verification_to_completed_is_rejected(): void
    {
        [$subscription] = $this->subscriptionWithNearbyOdp();
        $workOrder = app(WorkOrderService::class)->createFromSubscription($subscription);

        $this->assertSame(WorkOrderStatus::PendingVerification, $workOrder->status);

        $this->expectException(InvalidWorkOrderStatusTransitionException::class);
        app(WorkOrderService::class)->complete($workOrder);
    }

    public function test_illegal_transition_assigned_to_completed_without_in_progress_is_rejected(): void
    {
        [$subscription] = $this->subscriptionWithNearbyOdp();
        $service = app(WorkOrderService::class);
        $workOrder = $service->createFromSubscription($subscription);
        $workOrder = $service->verify($workOrder, true);

        $technician = Technician::factory()->create(['tenant_id' => $subscription->tenant_id]);
        $workOrder = $service->assignTechnician($workOrder, $technician);

        $this->assertSame(WorkOrderStatus::Assigned, $workOrder->status);

        $this->expectException(InvalidWorkOrderStatusTransitionException::class);
        $service->complete($workOrder);
    }

    public function test_complete_fails_when_photos_are_incomplete(): void
    {
        [$subscription] = $this->subscriptionWithNearbyOdp();
        $service = app(WorkOrderService::class);
        $workOrder = $service->createFromSubscription($subscription);
        $workOrder = $service->verify($workOrder, true);
        $technician = Technician::factory()->create(['tenant_id' => $subscription->tenant_id]);
        $workOrder = $service->assignTechnician($workOrder, $technician);
        $workOrder = $service->start($workOrder);

        // Only 1 of 4 required photo types, plus a device — still incomplete.
        WorkOrderPhoto::factory()->forWorkOrder($workOrder)->ofType(WorkOrderPhotoType::Odp)->create();
        $service->addDevice($workOrder, WorkOrderDeviceType::Ont, '00:11:22:33:44:55', 'SN12345');

        $this->expectException(IncompleteWorkOrderException::class);
        $service->complete($workOrder);
    }

    public function test_complete_fails_when_no_device_recorded(): void
    {
        [$subscription] = $this->subscriptionWithNearbyOdp();
        $service = app(WorkOrderService::class);
        $workOrder = $service->createFromSubscription($subscription);
        $workOrder = $service->verify($workOrder, true);
        $technician = Technician::factory()->create(['tenant_id' => $subscription->tenant_id]);
        $workOrder = $service->assignTechnician($workOrder, $technician);
        $workOrder = $service->start($workOrder);

        foreach (WorkOrderPhotoType::cases() as $type) {
            WorkOrderPhoto::factory()->forWorkOrder($workOrder)->ofType($type)->create();
        }

        $this->expectException(IncompleteWorkOrderException::class);
        $service->complete($workOrder);
    }

    // ── v0.12.7 Langkah 3 — guard konfirmasi teknisi di complete() ──

    public function test_complete_fails_when_technician_has_not_confirmed(): void
    {
        [$subscription] = $this->subscriptionWithNearbyOdp();
        $service = app(WorkOrderService::class);
        $workOrder = $service->createFromSubscription($subscription);
        $workOrder = $service->verify($workOrder, true);
        $technician = Technician::factory()->create(['tenant_id' => $subscription->tenant_id]);
        $workOrder = $service->assignTechnician($workOrder, $technician);
        $workOrder = $service->start($workOrder);

        // Foto + device LENGKAP -- satu-satunya yang kurang adalah
        // technician_confirmed_at (masih null, default).
        foreach (WorkOrderPhotoType::cases() as $type) {
            WorkOrderPhoto::factory()->forWorkOrder($workOrder)->ofType($type)->create();
        }
        $service->addDevice($workOrder, WorkOrderDeviceType::Ont, '00:11:22:33:44:55', 'SN12345');

        $this->assertNull($workOrder->fresh()->technician_confirmed_at);

        $this->expectException(WorkOrderNotConfirmedException::class);
        $this->expectExceptionMessage('Konfirmasi WhatsApp diperlukan sebelum instalasi bisa diselesaikan.');
        $service->complete($workOrder->fresh());
    }

    public function test_complete_succeeds_with_all_photos_and_a_device_and_marks_port_used(): void
    {
        [$subscription, $port] = $this->subscriptionWithNearbyOdp();
        $service = app(WorkOrderService::class);
        $workOrder = $service->createFromSubscription($subscription);
        $workOrder = $service->verify($workOrder, true);
        $technician = Technician::factory()->create(['tenant_id' => $subscription->tenant_id]);
        $workOrder = $service->assignTechnician($workOrder, $technician);
        $workOrder = $service->start($workOrder);

        foreach (WorkOrderPhotoType::cases() as $type) {
            WorkOrderPhoto::factory()->forWorkOrder($workOrder)->ofType($type)->create();
        }
        $service->addDevice($workOrder, WorkOrderDeviceType::Ont, '00:11:22:33:44:55', 'SN12345');

        // v0.12.7 Langkah 3 — gerbang konfirmasi teknisi.
        $workOrder->update(['technician_confirmed_at' => now()]);

        $workOrder = $service->complete($workOrder);

        $this->assertSame(WorkOrderStatus::Completed, $workOrder->status);
        $this->assertNotNull($workOrder->completed_at);
        $this->assertSame(OdpPortStatus::Used, $port->fresh()->status);
    }

    public function test_cancel_releases_a_reserved_port_back_to_available(): void
    {
        [$subscription, $port] = $this->subscriptionWithNearbyOdp();
        $service = app(WorkOrderService::class);
        $workOrder = $service->createFromSubscription($subscription);

        $this->assertSame(OdpPortStatus::Reserved, $port->fresh()->status);

        $workOrder = $service->cancel($workOrder);

        $this->assertSame(WorkOrderStatus::Cancelled, $workOrder->status);
        $this->assertSame(OdpPortStatus::Available, $port->fresh()->status);
    }

    // ── v0.12.7 Langkah 4 — trigger Push Konfig di complete() ──

    /**
     * Shape sama persis WanConfigPushServiceTest::deviceWithActiveWan1() —
     * WAN1 PPPoE genuinely aktif (Username terisi, bukan bridged) supaya
     * WanConfigPushService::push() sampai ke titik "delivered", bukan
     * skip di langkah mana pun sebelumnya.
     */
    private function fakeGenieAcsDeviceWithActiveWan1(string $genieAcsDeviceId): array
    {
        return [
            '_id' => $genieAcsDeviceId,
            '_deviceId' => ['_Manufacturer' => 'Huawei', '_OUI' => 'AABBCC', '_ProductClass' => 'ONT', '_SerialNumber' => 'SNPUSH001'],
            '_lastInform' => now()->toIso8601String(),
            'InternetGatewayDevice' => [
                'DeviceInfo' => ['ModelName' => ['_value' => 'HG8245H']],
                'WANDevice' => ['1' => ['WANConnectionDevice' => ['1' => ['WANPPPConnection' => ['1' => [
                    'Username' => ['_value' => 'olduser', '_type' => 'xsd:string'],
                    'ConnectionType' => ['_value' => 'IP_Routed', '_type' => 'xsd:string'],
                ]]]]]],
            ],
        ];
    }

    public function test_complete_triggers_a_delivered_wan_config_push_for_the_newly_bound_device(): void
    {
        [$subscription, $port] = $this->subscriptionWithNearbyOdp();

        $group = NetworkProfileGroup::factory()->create(['tenant_id' => $subscription->tenant_id]);
        $package = PppPackage::factory()->create([
            'tenant_id' => $subscription->tenant_id,
            'is_active' => true,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $subscription->tenant_id])->id,
        ]);
        $modemType = ModemType::factory()->create(['tenant_id' => $subscription->tenant_id]);
        WanConfigTemplate::factory()->create([
            'tenant_id' => $subscription->tenant_id,
            'ppp_package_id' => $package->id,
            'modem_type_id' => $modemType->id,
            'wan1_enabled' => true,
            'wan1_pppoe_username' => 'newuser',
            'wan1_pppoe_password' => 'newpass',
        ]);
        $subscription->customer->update(['ppp_package_id' => $package->id]);

        $service = app(WorkOrderService::class);
        $workOrder = $service->createFromSubscription($subscription);
        $workOrder = $service->verify($workOrder, true);
        $technician = Technician::factory()->create(['tenant_id' => $subscription->tenant_id]);
        $workOrder = $service->assignTechnician($workOrder, $technician);
        $workOrder = $service->start($workOrder);

        foreach (WorkOrderPhotoType::cases() as $type) {
            WorkOrderPhoto::factory()->forWorkOrder($workOrder)->ofType($type)->create();
        }
        $service->addDevice($workOrder, WorkOrderDeviceType::Ont, '00:11:22:33:44:55', 'SNPUSH001', $modemType->id);
        $workOrder->update(['technician_confirmed_at' => now()]);

        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-psb-1'], 202),
            '*genieacs-nbi*' => Http::response([$this->fakeGenieAcsDeviceWithActiveWan1('AABBCC-ONT-SNPUSH001')], 200),
        ]);

        $result = $service->complete($workOrder->fresh());

        $this->assertSame(WorkOrderStatus::Completed, $result->status);
        $cpeDevice = CpeDevice::where('serial_number', 'SNPUSH001')->firstOrFail();
        $this->assertSame($modemType->id, $cpeDevice->modem_type_id);
        $this->assertDatabaseHas('cpe_action_logs', [
            'cpe_device_id' => $cpeDevice->id,
            'action_type' => 'push_wan_config',
            'status' => 'delivered',
        ]);
    }

    /**
     * Best-effort, sama posture dengan hook renew() di v0.12.6 — kegagalan
     * push TIDAK BOLEH membatalkan complete() itu sendiri.
     */
    public function test_complete_succeeds_even_when_wan_config_push_fails(): void
    {
        [$subscription, $port] = $this->subscriptionWithNearbyOdp();

        $group = NetworkProfileGroup::factory()->create(['tenant_id' => $subscription->tenant_id]);
        $package = PppPackage::factory()->create([
            'tenant_id' => $subscription->tenant_id,
            'is_active' => true,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $subscription->tenant_id])->id,
        ]);
        $modemType = ModemType::factory()->create(['tenant_id' => $subscription->tenant_id]);
        WanConfigTemplate::factory()->create([
            'tenant_id' => $subscription->tenant_id,
            'ppp_package_id' => $package->id,
            'modem_type_id' => $modemType->id,
            'wan1_enabled' => true,
        ]);
        $subscription->customer->update(['ppp_package_id' => $package->id]);

        $service = app(WorkOrderService::class);
        $workOrder = $service->createFromSubscription($subscription);
        $workOrder = $service->verify($workOrder, true);
        $technician = Technician::factory()->create(['tenant_id' => $subscription->tenant_id]);
        $workOrder = $service->assignTechnician($workOrder, $technician);
        $workOrder = $service->start($workOrder);

        foreach (WorkOrderPhotoType::cases() as $type) {
            WorkOrderPhoto::factory()->forWorkOrder($workOrder)->ofType($type)->create();
        }
        $service->addDevice($workOrder, WorkOrderDeviceType::Ont, '00:11:22:33:44:55', 'SNPUSHFAIL01', $modemType->id);
        $workOrder->update(['technician_confirmed_at' => now()]);

        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['error' => 'bad request'], 400),
            '*genieacs-nbi*' => Http::response([[
                '_id' => 'AABBCC-ONT-SNPUSHFAIL01',
                '_deviceId' => ['_Manufacturer' => 'Huawei', '_OUI' => 'AABBCC', '_ProductClass' => 'ONT', '_SerialNumber' => 'SNPUSHFAIL01'],
                '_lastInform' => now()->toIso8601String(),
                'InternetGatewayDevice' => [
                    'DeviceInfo' => ['ModelName' => ['_value' => 'HG8245H']],
                    'WANDevice' => ['1' => ['WANConnectionDevice' => ['1' => ['WANPPPConnection' => ['1' => [
                        'Username' => ['_value' => 'olduser', '_type' => 'xsd:string'],
                        'ConnectionType' => ['_value' => 'IP_Routed', '_type' => 'xsd:string'],
                    ]]]]]],
                ],
            ]], 200),
        ]);

        // complete() harus tetap sukses meski push gagal.
        $result = $service->complete($workOrder->fresh());

        $this->assertSame(WorkOrderStatus::Completed, $result->status);
        $cpeDevice = CpeDevice::where('serial_number', 'SNPUSHFAIL01')->firstOrFail();
        $this->assertDatabaseHas('cpe_action_logs', [
            'cpe_device_id' => $cpeDevice->id,
            'action_type' => 'push_wan_config',
            'status' => 'failed',
        ]);
    }
}
