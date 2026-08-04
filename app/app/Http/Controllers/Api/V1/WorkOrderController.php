<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderPhotoType;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignWorkOrderRequest;
use App\Http\Requests\StoreWorkOrderDeviceRequest;
use App\Http\Requests\StoreWorkOrderPhotoRequest;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Requests\VerifyWorkOrderRequest;
use App\Http\Resources\WorkOrderResource;
use App\Models\Subscription;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Services\Installation\WorkOrderPhotoService;
use App\Services\Installation\WorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkOrderController extends Controller
{
    use ApiResponds;

    private const WITH = ['customer', 'technician', 'odp', 'odpPort', 'devices', 'photos'];

    /**
     * BelongsToResellerScope narrows this to the reseller's own work
     * orders; an ISP admin (no context) sees every work order.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WorkOrder::class);

        $workOrders = WorkOrder::query()
            ->with(self::WITH)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            WorkOrderResource::collection($workOrders->items()),
            'Daftar work order',
            ['pagination' => [
                'current_page' => $workOrders->currentPage(),
                'per_page' => $workOrders->perPage(),
                'total' => $workOrders->total(),
                'last_page' => $workOrders->lastPage(),
            ]]
        );
    }

    public function show(WorkOrder $work_order): JsonResponse
    {
        $this->authorize('view', $work_order);

        return $this->success(new WorkOrderResource($work_order->load(self::WITH)));
    }

    /**
     * Manual creation by an admin/CS — same underlying flow as the
     * subscription-triggered path below, just picking the subscription
     * explicitly instead of it being implied by the route.
     */
    public function store(StoreWorkOrderRequest $request, WorkOrderService $service): JsonResponse
    {
        $subscription = Subscription::findOrFail($request->validated('subscription_id'));

        $workOrder = $service->createFromSubscription($subscription);

        return $this->success(new WorkOrderResource($workOrder->load(self::WITH)), 'Work order berhasil dibuat', [], 201);
    }

    public function storeFromSubscription(Subscription $subscription, WorkOrderService $service): JsonResponse
    {
        $this->authorize('create', [WorkOrder::class, $subscription]);

        $workOrder = $service->createFromSubscription($subscription);

        return $this->success(new WorkOrderResource($workOrder->load(self::WITH)), 'Work order berhasil dibuat', [], 201);
    }

    public function verify(VerifyWorkOrderRequest $request, WorkOrder $work_order, WorkOrderService $service): JsonResponse
    {
        $workOrder = $service->verify($work_order, $request->boolean('equipment_ready'));

        return $this->success(new WorkOrderResource($workOrder->load(self::WITH)), 'Work order diverifikasi');
    }

    public function assign(AssignWorkOrderRequest $request, WorkOrder $work_order, WorkOrderService $service): JsonResponse
    {
        $technician = Technician::findOrFail($request->validated('technician_id'));

        $workOrder = $service->assignTechnician($work_order, $technician);

        return $this->success(new WorkOrderResource($workOrder->load(self::WITH)), 'Teknisi berhasil ditugaskan');
    }

    public function start(WorkOrder $work_order, WorkOrderService $service): JsonResponse
    {
        $this->authorize('manage', $work_order);

        $workOrder = $service->start($work_order);

        return $this->success(new WorkOrderResource($workOrder->load(self::WITH)), 'Work order dimulai');
    }

    public function storePhoto(StoreWorkOrderPhotoRequest $request, WorkOrder $work_order, WorkOrderPhotoService $service): JsonResponse
    {
        $photo = $service->store(
            $work_order,
            WorkOrderPhotoType::from($request->validated('type')),
            $request->file('file')
        );

        return $this->success($photo, 'Foto berhasil diunggah', [], 201);
    }

    public function storeDevice(StoreWorkOrderDeviceRequest $request, WorkOrder $work_order, WorkOrderService $service): JsonResponse
    {
        $device = $service->addDevice(
            $work_order,
            WorkOrderDeviceType::from($request->validated('device_type')),
            $request->validated('mac_address'),
            $request->validated('serial_number')
        );

        return $this->success($device, 'Perangkat berhasil dicatat', [], 201);
    }

    public function complete(WorkOrder $work_order, WorkOrderService $service): JsonResponse
    {
        $this->authorize('manage', $work_order);

        $workOrder = $service->complete($work_order);

        return $this->success(new WorkOrderResource($workOrder->load(self::WITH)), 'Work order selesai');
    }

    public function cancel(WorkOrder $work_order, WorkOrderService $service): JsonResponse
    {
        $this->authorize('manage', $work_order);

        $workOrder = $service->cancel($work_order);

        return $this->success(new WorkOrderResource($workOrder->load(self::WITH)), 'Work order dibatalkan');
    }
}
