<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaxComponentRequest;
use App\Http\Requests\UpdateTaxComponentRateRequest;
use App\Http\Requests\UpdateTaxComponentRequest;
use App\Http\Resources\TaxComponentResource;
use App\Models\TaxComponent;
use App\Services\Tax\TaxComponentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TaxComponentController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TaxComponent::class);

        $components = TaxComponent::query()
            ->when($request->filled('code'), fn ($q) => $q->where('code', $request->string('code')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->latest('effective_from')
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            TaxComponentResource::collection($components->items()),
            'Daftar tax component',
            ['pagination' => [
                'current_page' => $components->currentPage(),
                'per_page' => $components->perPage(),
                'total' => $components->total(),
                'last_page' => $components->lastPage(),
            ]]
        );
    }

    public function store(StoreTaxComponentRequest $request, TaxComponentService $service): JsonResponse
    {
        $component = $service->create($request->validated());

        return $this->success(new TaxComponentResource($component), 'Tax component berhasil dibuat', [], 201);
    }

    public function show(TaxComponent $taxComponent): JsonResponse
    {
        $this->authorize('view', $taxComponent);

        return $this->success(new TaxComponentResource($taxComponent));
    }

    public function update(UpdateTaxComponentRequest $request, TaxComponent $taxComponent): JsonResponse
    {
        $taxComponent->update($request->validated());

        return $this->success(new TaxComponentResource($taxComponent), 'Tax component berhasil diperbarui');
    }

    /**
     * Dedicated effective-dated rate change — see
     * App\Services\Tax\TaxComponentService::updateRate(). Returns the NEW
     * tax_components row (a different id from $taxComponent).
     */
    public function updateRate(UpdateTaxComponentRateRequest $request, TaxComponent $taxComponent, TaxComponentService $service): JsonResponse
    {
        $newComponent = $service->updateRate(
            $taxComponent,
            (float) $request->validated('rate'),
            Carbon::parse($request->validated('effective_from'))
        );

        return $this->success(new TaxComponentResource($newComponent), 'Tarif baru berlaku efektif '.$newComponent->effective_from->toDateString());
    }
}
