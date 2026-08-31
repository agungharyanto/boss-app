<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSplitterRequest;
use App\Http\Resources\SplitterResource;
use App\Models\Splitter;
use App\Services\Network\FiberTopologyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * See FiberCableController's own docblock for why this uses a raw
 * permission-string check instead of a per-model Policy.
 */
class SplitterController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('network_infrastructure.view') || $request->user()->can('network_infrastructure.manage'), 403);

        $splitters = Splitter::query()
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            SplitterResource::collection($splitters->items()),
            'Daftar splitter',
            ['pagination' => [
                'current_page' => $splitters->currentPage(),
                'per_page' => $splitters->perPage(),
                'total' => $splitters->total(),
                'last_page' => $splitters->lastPage(),
            ]]
        );
    }

    public function store(StoreSplitterRequest $request, FiberTopologyService $service): JsonResponse
    {
        $splitter = $service->createSplitter($request->validated());

        return $this->success(new SplitterResource($splitter), 'Splitter berhasil dibuat', [], 201);
    }
}
