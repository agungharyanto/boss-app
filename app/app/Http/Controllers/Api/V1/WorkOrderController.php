<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderPhotoType;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignWorkOrderRequest;
use App\Http\Requests\ConfirmWorkOrderRequest;
use App\Http\Requests\ProvisionWorkOrderDeviceRequest;
use App\Http\Requests\StoreWorkOrderDeviceRequest;
use App\Http\Requests\StoreWorkOrderPhotoRequest;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Requests\VerifyWorkOrderRequest;
use App\Http\Resources\WorkOrderResource;
use App\Models\Subscription;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use App\Policies\WorkOrderPolicy;
use App\Services\Installation\TechnicianOtpException;
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
     *
     * v0.12.3 — kalau acting user MURNI teknisi (WorkOrderPolicy::
     * isTechnicianOnly(), bukan admin/.manage-wide/reseller membership),
     * query di-scope ke WorkOrderPolicy::scopeForTechnician() — definisi
     * visibility YANG SAMA persis dengan authorize('view', ...) per-objek,
     * bukan dua definisi terpisah yang bisa drift.
     */
    public function index(Request $request, WorkOrderPolicy $policy): JsonResponse
    {
        $this->authorize('viewAny', WorkOrder::class);

        $workOrders = WorkOrder::query()
            ->with(self::WITH)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($policy->isTechnicianOnly($request->user()), fn ($q) => $policy->scopeForTechnician($request->user(), $q))
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
            $request->validated('serial_number'),
            $request->validated('modem_type_id')
        );

        return $this->success($device, 'Perangkat berhasil dicatat', [], 201);
    }

    /**
     * v0.7.5 bridge endpoint, sekarang JUGA technician self-service sejak
     * v0.12.3 (lihat ProvisionWorkOrderDeviceRequest's own docblock).
     * genieacs_device_id/wifi_provisioned_at aren't touched here at all —
     * this only records the credential; the actual push happens later,
     * from CpeBindingService, once the device is known to GenieACS (may
     * already have happened by binding time, or may still be pending —
     * this endpoint doesn't need to know which).
     */
    public function provisionDevice(ProvisionWorkOrderDeviceRequest $request, WorkOrder $work_order, WorkOrderDevice $device, WorkOrderService $service): JsonResponse
    {
        $updated = $service->provisionDeviceWifi($work_order, $device, $request->validated());

        return $this->success($updated, 'Kredensial WiFi tercatat — akan didorong ke perangkat begitu dikenal GenieACS.');
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

    /**
     * v0.12.3 — klaim mandiri (self-service). `view` (bukan `manage`) —
     * kalau WO belum kelihatan sama sekali (sudah di-assign/diklaim
     * teknisi LAIN), authorize() ini sudah menolak sebelum sampai ke
     * WorkOrderService::claim() — teknisi lain tidak pernah bisa "merebut"
     * klaim yang sudah ada.
     */
    public function claim(WorkOrder $work_order, WorkOrderService $service): JsonResponse
    {
        $this->authorize('view', $work_order);

        $technician = Technician::query()->where('user_id', request()->user()->id)->first();

        if ($technician === null) {
            abort(403, 'Akun ini tidak tertaut ke Technician — tidak bisa mengklaim work order.');
        }

        $claim = $service->claim($work_order, $technician);

        return $this->success(['claimed_at' => $claim->claimed_at->toIso8601String()], 'Work order berhasil diklaim');
    }

    /**
     * v0.12.7 Langkah 3.1 — mengirim OTP WhatsApp ke nomor teknisi sendiri
     * (`technicians.phone`, BUKAN pelanggan) untuk mengonfirmasi instalasi
     * yang baru selesai dia kerjakan. `manage` (bukan `view` seperti
     * claim() di atas) — endpoint ini bagian dari alur SETELAH teknisi
     * sudah mengerjakan WO (assigned/claimed), bukan "melihat sebelum
     * mengklaim".
     */
    public function requestConfirmation(WorkOrder $work_order, WorkOrderService $service): JsonResponse
    {
        $this->authorize('manage', $work_order);

        $technician = Technician::query()->where('user_id', request()->user()->id)->first();

        if ($technician === null) {
            abort(403, 'Akun ini tidak tertaut ke Technician — tidak bisa meminta konfirmasi work order.');
        }

        try {
            $service->requestConfirmation($work_order, $technician);
        } catch (TechnicianOtpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'data' => null, 'meta' => []], 422);
        }

        return $this->success(null, 'Kode konfirmasi dikirim ke WhatsApp Anda.');
    }

    /**
     * v0.12.7 Langkah 3.2 — verifikasi kode OTP dari
     * requestConfirmation() di atas. Sukses -> work_orders.
     * technician_confirmed_at terisi, gerbang terakhir sebelum
     * WorkOrderService::complete() (lihat guard di method itu sendiri —
     * Langkah 3.3).
     */
    public function confirm(ConfirmWorkOrderRequest $request, WorkOrder $work_order, WorkOrderService $service): JsonResponse
    {
        $technician = Technician::query()->where('user_id', request()->user()->id)->first();

        if ($technician === null) {
            abort(403, 'Akun ini tidak tertaut ke Technician — tidak bisa mengonfirmasi work order.');
        }

        try {
            $workOrder = $service->confirmByTechnician($work_order, $technician, $request->validated('code'));
        } catch (TechnicianOtpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'data' => null, 'meta' => []], 422);
        }

        return $this->success(['technician_confirmed_at' => $workOrder->technician_confirmed_at->toIso8601String()], 'Instalasi berhasil dikonfirmasi.');
    }

    /**
     * v0.12.3 — lookup WorkOrder lewat serial number perangkat yang sudah
     * di-scan (`work_order_devices.serial_number`). Query lewat relasi
     * WorkOrder (bukan `WorkOrderDevice::where(...)` langsung) SENGAJA —
     * WorkOrderDevice sendiri tidak tenant-scoped, WorkOrder::query() sudah
     * (BelongsToTenant), jadi ini menghindari kebocoran lintas-tenant kalau
     * kebetulan ada serial yang sama persis di tenant lain.
     */
    public function lookupBySerial(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WorkOrder::class);

        $serial = $request->string('serial')->trim()->toString();

        if ($serial === '') {
            abort(422, 'Parameter serial wajib diisi.');
        }

        $workOrder = WorkOrder::query()
            ->whereHas('devices', fn ($q) => $q->where('serial_number', $serial))
            ->first();

        if ($workOrder === null) {
            abort(404, 'Tidak ada work order dengan serial number tersebut.');
        }

        $this->authorize('view', $workOrder);

        return $this->success(new WorkOrderResource($workOrder->load(self::WITH)));
    }
}
