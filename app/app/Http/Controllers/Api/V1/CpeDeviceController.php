<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\CpeDeviceResource;
use App\Models\CpeDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only this sprint (v0.7.1) — no store/update/destroy at all. Binding
 * is fully automatic via CpeBindingService::bindFromWorkOrder(), never a
 * user-facing create action, per the locked decision "otomatis dari
 * Installation, bukan input admin".
 */
class CpeDeviceController extends Controller
{
    use ApiResponds;

    /**
     * BelongsToResellerScope narrows this to the reseller's own devices; an
     * ISP admin (no context) sees every device including direct ones —
     * same posture as OdpController::index().
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CpeDevice::class);

        $devices = CpeDevice::query()
            ->with(['customer', 'reseller'])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            CpeDeviceResource::collection($devices->items()),
            'Daftar perangkat CPE',
            ['pagination' => [
                'current_page' => $devices->currentPage(),
                'per_page' => $devices->perPage(),
                'total' => $devices->total(),
                'last_page' => $devices->lastPage(),
            ]]
        );
    }

    public function show(CpeDevice $cpeDevice): JsonResponse
    {
        $this->authorize('view', $cpeDevice);

        return $this->success(new CpeDeviceResource($cpeDevice->load(['customer', 'reseller'])));
    }
}
