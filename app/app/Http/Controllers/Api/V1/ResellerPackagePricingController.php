<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResellerPackagePricingRequest;
use App\Http\Requests\UpdateResellerPackagePricingRequest;
use App\Http\Resources\ResellerPackagePricingResource;
use App\Models\Reseller;
use App\Models\ResellerPackagePricing;
use App\Services\ResellerPackagePricingService;
use App\Support\ResellerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResellerPackagePricingController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ResellerPackagePricing::class);

        // ResellerScope already narrows this to "my own reseller" for a
        // reseller owner/staff caller (see App\Support\ResellerContext).
        // For an ISP admin (no context), ?reseller_id= is an optional
        // explicit filter — omitting it lists pricing across all resellers.
        $pricing = ResellerPackagePricing::query()
            ->with('reseller')
            ->when($request->filled('reseller_id'), fn ($query) => $query->where('reseller_id', $request->integer('reseller_id')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            ResellerPackagePricingResource::collection($pricing->items()),
            'Daftar reseller package pricing',
            [
                'pagination' => [
                    'current_page' => $pricing->currentPage(),
                    'per_page' => $pricing->perPage(),
                    'total' => $pricing->total(),
                    'last_page' => $pricing->lastPage(),
                ],
            ]
        );
    }

    public function store(StoreResellerPackagePricingRequest $request, ResellerPackagePricingService $service, ResellerContext $context): JsonResponse
    {
        $data = $request->validated();
        $resellerId = $context->reseller()?->id ?? $data['reseller_id'];
        unset($data['reseller_id']);

        $reseller = Reseller::findOrFail($resellerId);

        $pricing = $service->createPackage($reseller, $data);

        return $this->success(new ResellerPackagePricingResource($pricing), 'Package pricing berhasil dibuat', [], 201);
    }

    public function show(ResellerPackagePricing $pricing): JsonResponse
    {
        $this->authorize('view', $pricing);

        return $this->success(new ResellerPackagePricingResource($pricing->load('reseller')));
    }

    public function update(UpdateResellerPackagePricingRequest $request, ResellerPackagePricing $pricing, ResellerPackagePricingService $service): JsonResponse
    {
        $pricing = $service->updatePackage($pricing, $request->validated());

        return $this->success(new ResellerPackagePricingResource($pricing), 'Package pricing berhasil diperbarui');
    }

    public function destroy(ResellerPackagePricing $pricing): JsonResponse
    {
        $this->authorize('delete', $pricing);

        $pricing->delete();

        return $this->success(null, 'Package pricing berhasil dihapus');
    }
}
