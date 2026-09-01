<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommissionRateRequest;
use App\Http\Requests\UpdateCommissionRateRequest;
use App\Http\Resources\CommissionRateResource;
use App\Models\CommissionRate;
use App\Models\PppPackage;
use App\Services\CommissionRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * v0.9.3 — Commission Rate Settings (tier-admin-only, lihat
 * CommissionRatePolicy). Tenant-level, tidak butuh reseller.context —
 * sama posture dengan referrers.* / bandwidth-profiles.*.
 */
class CommissionRateController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CommissionRate::class);

        $rates = CommissionRate::query()
            ->with('pppPackage')
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            CommissionRateResource::collection($rates->items()),
            'Daftar rate komisi',
            [
                'pagination' => [
                    'current_page' => $rates->currentPage(),
                    'per_page' => $rates->perPage(),
                    'total' => $rates->total(),
                    'last_page' => $rates->lastPage(),
                ],
            ]
        );
    }

    public function store(StoreCommissionRateRequest $request, CommissionRateService $service): JsonResponse
    {
        // ppp_package_id sudah divalidasi (tenant + not-deleted); ambil
        // lewat query tenant-scoped supaya route/service tidak pernah
        // menyentuh paket tenant lain.
        $package = PppPackage::findOrFail($request->integer('ppp_package_id'));

        $rate = $service->createForPackage($package, $request->safe()->except('ppp_package_id'));

        return $this->success(
            new CommissionRateResource($rate->load('pppPackage')),
            'Rate komisi berhasil dibuat',
            [],
            201
        );
    }

    public function show(CommissionRate $commission_rate): JsonResponse
    {
        $this->authorize('view', $commission_rate);

        return $this->success(new CommissionRateResource($commission_rate->load('pppPackage')));
    }

    public function update(UpdateCommissionRateRequest $request, CommissionRate $commission_rate, CommissionRateService $service): JsonResponse
    {
        $rate = $service->update($commission_rate, $request->validated());

        return $this->success(
            new CommissionRateResource($rate->load('pppPackage')),
            'Rate komisi berhasil diperbarui'
        );
    }

    public function destroy(CommissionRate $commission_rate, CommissionRateService $service): JsonResponse
    {
        $this->authorize('manage', CommissionRate::class);

        $service->delete($commission_rate);

        return $this->success(null, 'Rate komisi berhasil dihapus');
    }
}
