<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOdpRequest;
use App\Http\Requests\UpdateOdpRequest;
use App\Http\Resources\OdpResource;
use App\Models\Odp;
use App\Support\ResellerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OdpController extends Controller
{
    use ApiResponds;

    /**
     * BelongsToResellerScope narrows this to the reseller's own ODPs; an
     * ISP admin (no context) sees every ODP including direct ones.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Odp::class);

        $odps = Odp::query()
            ->with(['reseller', 'ports'])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            OdpResource::collection($odps->items()),
            'Daftar ODP',
            ['pagination' => [
                'current_page' => $odps->currentPage(),
                'per_page' => $odps->perPage(),
                'total' => $odps->total(),
                'last_page' => $odps->lastPage(),
            ]]
        );
    }

    public function store(StoreOdpRequest $request, ResellerContext $context): JsonResponse
    {
        $data = $request->validated();
        $resellerId = $context->hasReseller() ? $context->reseller()->id : ($data['reseller_id'] ?? null);
        unset($data['reseller_id']);

        $odp = Odp::create([
            ...$data,
            'tenant_id' => $request->user()->tenant_id,
            'reseller_id' => $resellerId,
        ]);

        $odp->provisionPorts();

        return $this->success(new OdpResource($odp->load('ports')), 'ODP berhasil dibuat', [], 201);
    }

    public function show(Odp $odp): JsonResponse
    {
        $this->authorize('view', $odp);

        return $this->success(new OdpResource($odp->load(['reseller', 'ports'])));
    }

    public function update(UpdateOdpRequest $request, Odp $odp): JsonResponse
    {
        $odp->update($request->validated());

        return $this->success(new OdpResource($odp->fresh()), 'ODP berhasil diperbarui');
    }

    public function destroy(Odp $odp): JsonResponse
    {
        $this->authorize('manage', $odp);

        $odp->delete();

        return $this->success(null, 'ODP berhasil dihapus');
    }
}
