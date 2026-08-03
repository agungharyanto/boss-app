<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResellerRequest;
use App\Http\Requests\UpdateResellerRequest;
use App\Http\Resources\ResellerResource;
use App\Models\Reseller;
use App\Services\ResellerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResellerController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Reseller::class);

        $resellers = Reseller::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            ResellerResource::collection($resellers->items()),
            'Daftar reseller',
            [
                'pagination' => [
                    'current_page' => $resellers->currentPage(),
                    'per_page' => $resellers->perPage(),
                    'total' => $resellers->total(),
                    'last_page' => $resellers->lastPage(),
                ],
            ]
        );
    }

    public function store(StoreResellerRequest $request, ResellerService $service): JsonResponse
    {
        $reseller = $service->createReseller($request->validated());

        return $this->success(new ResellerResource($reseller), 'Reseller berhasil dibuat', [], 201);
    }

    public function show(Reseller $reseller): JsonResponse
    {
        $this->authorize('view', $reseller);

        return $this->success(new ResellerResource($reseller));
    }

    public function update(UpdateResellerRequest $request, Reseller $reseller, ResellerService $service): JsonResponse
    {
        $reseller = $service->updateReseller($reseller, $request->validated());

        return $this->success(new ResellerResource($reseller), 'Reseller berhasil diperbarui');
    }

    public function destroy(Reseller $reseller): JsonResponse
    {
        $this->authorize('delete', $reseller);

        $reseller->delete();

        return $this->success(null, 'Reseller berhasil dihapus');
    }
}
