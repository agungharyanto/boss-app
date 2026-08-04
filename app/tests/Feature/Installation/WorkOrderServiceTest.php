<?php

namespace Tests\Feature\Installation;

use App\Enums\OdpPortStatus;
use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderPhotoType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\IncompleteWorkOrderException;
use App\Exceptions\InvalidWorkOrderStatusTransitionException;
use App\Models\Customer;
use App\Models\Odp;
use App\Models\OdpPort;
use App\Models\Subscription;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\WorkOrderPhoto;
use App\Services\Installation\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
