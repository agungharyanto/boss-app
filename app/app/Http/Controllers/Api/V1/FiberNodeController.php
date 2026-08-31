<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFiberNodeRequest;
use App\Http\Requests\UpdateFiberNodeRequest;
use App\Http\Resources\FiberNodeResource;
use App\Models\FiberNode;
use App\Services\Network\FiberTopologyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 3. Thin
 * Controller per BOSS-006 — every real operation delegates to
 * FiberTopologyService.
 */
class FiberNodeController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FiberNode::class);

        $nodes = FiberNode::query()
            ->when($request->filled('node_type'), fn ($query) => $query->where('node_type', $request->string('node_type')))
            ->when($request->filled('search'), fn ($query) => $query->where('local_label', 'like', '%'.$request->string('search').'%'))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            FiberNodeResource::collection($nodes->items()),
            'Daftar titik topologi fiber',
            ['pagination' => [
                'current_page' => $nodes->currentPage(),
                'per_page' => $nodes->perPage(),
                'total' => $nodes->total(),
                'last_page' => $nodes->lastPage(),
            ]]
        );
    }

    public function store(StoreFiberNodeRequest $request, FiberTopologyService $service): JsonResponse
    {
        $node = $service->createNode($request->validated());

        return $this->success(new FiberNodeResource($node), 'Titik topologi fiber berhasil dibuat', [], 201);
    }

    public function show(FiberNode $fiber_node): JsonResponse
    {
        $this->authorize('view', $fiber_node);

        return $this->success(new FiberNodeResource($fiber_node->load('photos')));
    }

    public function update(UpdateFiberNodeRequest $request, FiberNode $fiber_node, FiberTopologyService $service): JsonResponse
    {
        $node = $service->updateNode($fiber_node, $request->validated());

        return $this->success(new FiberNodeResource($node), 'Titik topologi fiber berhasil diperbarui');
    }

    public function destroy(FiberNode $fiber_node, FiberTopologyService $service): JsonResponse
    {
        $this->authorize('manage', $fiber_node);

        $service->deleteNode($fiber_node);

        return $this->success(null, 'Titik topologi fiber berhasil dihapus');
    }
}
