<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNasRequest;
use App\Http\Requests\UpdateNasRequest;
use App\Http\Resources\NasResource;
use App\Models\Nas;
use App\Services\Network\NasService;
use App\Support\ResellerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NasController extends Controller
{
    use ApiResponds;

    /**
     * BelongsToResellerScope narrows this to the reseller's own NAS; an ISP
     * admin (no context) sees every NAS including direct ones.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Nas::class);

        $nas = Nas::query()
            ->with('reseller')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            NasResource::collection($nas->items()),
            'Daftar NAS',
            ['pagination' => [
                'current_page' => $nas->currentPage(),
                'per_page' => $nas->perPage(),
                'total' => $nas->total(),
                'last_page' => $nas->lastPage(),
            ]]
        );
    }

    public function store(StoreNasRequest $request, ResellerContext $context, NasService $service): JsonResponse
    {
        $data = $request->validated();
        $resellerId = $context->hasReseller() ? $context->reseller()->id : ($data['reseller_id'] ?? null);
        unset($data['reseller_id']);

        $nas = $service->create($data, $request->user()->tenant_id, $resellerId);

        return $this->success(new NasResource($nas), 'NAS berhasil dibuat', [], 201);
    }

    public function show(Nas $nas): JsonResponse
    {
        $this->authorize('view', $nas);

        return $this->success(new NasResource($nas->load('reseller')));
    }

    public function update(UpdateNasRequest $request, Nas $nas, NasService $service): JsonResponse
    {
        $nas = $service->update($nas, $request->validated());

        return $this->success(new NasResource($nas), 'NAS berhasil diperbarui');
    }

    public function destroy(Nas $nas, NasService $service): JsonResponse
    {
        $this->authorize('manage', $nas);

        $service->delete($nas);

        return $this->success(null, 'NAS berhasil dihapus');
    }

    public function testConnection(Nas $nas, NasService $service): JsonResponse
    {
        $this->authorize('manage', $nas);

        $nas = $service->testConnection($nas);

        return $this->success(new NasResource($nas), 'Tes koneksi selesai');
    }
}
