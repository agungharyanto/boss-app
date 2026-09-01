<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFiberCableRequest;
use App\Http\Resources\FiberCableResource;
use App\Models\FiberCable;
use App\Services\Network\FiberTopologyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * No FiberCablePolicy — only FiberNodePolicy exists this Langkah.
 * FiberCable/Splitter/FiberAccessory all share the single
 * network_infrastructure.view/.manage permission pair with no per-row
 * distinction, so a raw permission-string check (view OR manage for
 * reads, manage for writes) is sufficient — same "no Eloquent-model-
 * specific Policy needed for a plain permission check" posture already
 * established by MonitoringController.
 */
class FiberCableController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('network_infrastructure.view') || $request->user()->can('network_infrastructure.manage'), 403);

        $cables = FiberCable::query()
            ->withCount('cores')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            FiberCableResource::collection($cables->items()),
            'Daftar kabel fiber',
            ['pagination' => [
                'current_page' => $cables->currentPage(),
                'per_page' => $cables->perPage(),
                'total' => $cables->total(),
                'last_page' => $cables->lastPage(),
            ]]
        );
    }

    public function store(StoreFiberCableRequest $request, FiberTopologyService $service): JsonResponse
    {
        $cable = $service->createCable($request->validated());

        return $this->success(new FiberCableResource($cable->loadCount('cores')), 'Kabel fiber berhasil dibuat', [], 201);
    }
}
