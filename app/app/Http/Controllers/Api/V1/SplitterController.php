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
 *
 * v0.16.0 Langkah 4 — index() now scopes via Splitter::tenantScoped(). A
 * real cross-tenant leak was found here while building the Langkah 4
 * capacity report: Splitter has no tenant_id of its own (scoped
 * implicitly through its polymorphic owner, see that model's own
 * docblock), and this query never filtered by it at all — every tenant's
 * splitters were returned to every caller holding
 * network_infrastructure.view/.manage, regardless of which tenant they
 * belonged to.
 */
class SplitterController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('network_infrastructure.view') || $request->user()->can('network_infrastructure.manage'), 403);

        $splitters = Splitter::query()
            ->tenantScoped()
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
