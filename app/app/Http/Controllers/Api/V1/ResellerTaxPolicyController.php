<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResellerTaxPolicyRequest;
use App\Http\Requests\UpdateResellerTaxPolicyRequest;
use App\Http\Resources\ResellerTaxPolicyResource;
use App\Models\Reseller;
use App\Models\ResellerTaxPolicy;
use App\Models\TaxComponent;
use App\Services\Tax\ResellerTaxPolicyService;
use App\Support\ResellerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ResellerTaxPolicyController extends Controller
{
    use ApiResponds;

    /**
     * Scoped manually here (not via a model global scope, deliberately —
     * see ResellerTaxPolicyService, whose internal queries rely on precise
     * reseller_id IS NULL vs reseller_id = X filtering that a blanket
     * global scope would corrupt for the direct-retail-fallback lookup).
     * A resolved reseller context narrows the listing to that reseller's
     * own policies only; an ISP admin (no context) sees everything,
     * optionally filtered by ?reseller_id=.
     */
    public function index(Request $request, ResellerContext $context): JsonResponse
    {
        $this->authorize('viewAny', ResellerTaxPolicy::class);

        $policies = ResellerTaxPolicy::query()
            ->with(['reseller', 'taxComponent'])
            ->when(
                $context->hasReseller(),
                fn ($q) => $q->where('reseller_id', $context->reseller()->id),
                fn ($q) => $q->when($request->filled('reseller_id'), fn ($q2) => $q2->where('reseller_id', $request->integer('reseller_id')))
            )
            ->latest('effective_from')
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            ResellerTaxPolicyResource::collection($policies->items()),
            'Daftar reseller tax policy',
            ['pagination' => [
                'current_page' => $policies->currentPage(),
                'per_page' => $policies->perPage(),
                'total' => $policies->total(),
                'last_page' => $policies->lastPage(),
            ]]
        );
    }

    public function store(StoreResellerTaxPolicyRequest $request, ResellerTaxPolicyService $service): JsonResponse
    {
        $data = $request->validated();

        $reseller = isset($data['reseller_id']) ? Reseller::findOrFail($data['reseller_id']) : null;
        $component = TaxComponent::findOrFail($data['tax_component_id']);

        $policy = $service->setPolicy(
            $reseller,
            $component,
            $data['burden'],
            isset($data['split_ratio']) ? (float) $data['split_ratio'] : null,
            Carbon::parse($data['effective_from'])
        );

        return $this->success(new ResellerTaxPolicyResource($policy->load(['reseller', 'taxComponent'])), 'Tax policy berhasil diset', [], 201);
    }

    public function show(ResellerTaxPolicy $resellerTaxPolicy): JsonResponse
    {
        $this->authorize('view', $resellerTaxPolicy);

        return $this->success(new ResellerTaxPolicyResource($resellerTaxPolicy->load(['reseller', 'taxComponent'])));
    }

    public function update(UpdateResellerTaxPolicyRequest $request, ResellerTaxPolicy $resellerTaxPolicy, ResellerTaxPolicyService $service): JsonResponse
    {
        $data = $request->validated();

        $policy = $service->setPolicy(
            $resellerTaxPolicy->reseller,
            $resellerTaxPolicy->taxComponent,
            $data['burden'],
            isset($data['split_ratio']) ? (float) $data['split_ratio'] : null,
            Carbon::parse($data['effective_from'])
        );

        return $this->success(new ResellerTaxPolicyResource($policy->load(['reseller', 'taxComponent'])), 'Tax policy berhasil diperbarui');
    }
}
