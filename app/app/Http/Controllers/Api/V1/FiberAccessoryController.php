<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFiberAccessoryRequest;
use App\Http\Resources\FiberAccessoryResource;
use App\Models\FiberAccessory;
use App\Services\Network\FiberTopologyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * See FiberCableController's own docblock for why this uses a raw
 * permission-string check instead of a per-model Policy.
 */
class FiberAccessoryController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('network_infrastructure.view') || $request->user()->can('network_infrastructure.manage'), 403);

        $accessories = FiberAccessory::query()
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            FiberAccessoryResource::collection($accessories->items()),
            'Daftar aksesori fiber',
            ['pagination' => [
                'current_page' => $accessories->currentPage(),
                'per_page' => $accessories->perPage(),
                'total' => $accessories->total(),
                'last_page' => $accessories->lastPage(),
            ]]
        );
    }

    public function store(StoreFiberAccessoryRequest $request, FiberTopologyService $service): JsonResponse
    {
        $accessory = $service->createAccessory($request->validated());

        return $this->success(new FiberAccessoryResource($accessory), 'Aksesori fiber berhasil dibuat', [], 201);
    }
}
