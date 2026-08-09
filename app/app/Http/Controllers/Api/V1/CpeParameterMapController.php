<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCpeParameterMapRequest;
use App\Http\Requests\UpdateCpeParameterMapRequest;
use App\Http\Resources\CpeParameterMapResource;
use App\Models\CpeParameterMap;
use App\Services\Network\CpeParameterResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CpeParameterMapController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CpeParameterMap::class);

        $maps = CpeParameterMap::query()
            ->when($request->filled('oui'), fn ($q) => $q->where('oui', $request->string('oui')))
            ->when($request->filled('product_class'), fn ($q) => $q->where('product_class', $request->string('product_class')))
            ->orderBy('oui')
            ->orderBy('product_class')
            ->orderBy('parameter_key')
            ->get();

        return $this->success(CpeParameterMapResource::collection($maps), 'Daftar mapping parameter CPE');
    }

    public function store(StoreCpeParameterMapRequest $request): JsonResponse
    {
        $map = CpeParameterMap::query()->create($request->validated());

        return $this->success(new CpeParameterMapResource($map), 'Mapping parameter CPE dibuat', status: 201);
    }

    public function show(CpeParameterMap $cpeParameterMap): JsonResponse
    {
        $this->authorize('view', CpeParameterMap::class);

        return $this->success(new CpeParameterMapResource($cpeParameterMap));
    }

    public function update(UpdateCpeParameterMapRequest $request, CpeParameterMap $cpeParameterMap): JsonResponse
    {
        // Editing the definition demotes it back to unverified — see
        // UpdateCpeParameterMapRequest's own docblock for why this isn't
        // just "forgot to add the field".
        $cpeParameterMap->fill($request->validated());
        $cpeParameterMap->verified_at = null;
        $cpeParameterMap->verified_against_device_id = null;
        $cpeParameterMap->save();

        return $this->success(new CpeParameterMapResource($cpeParameterMap), 'Mapping parameter CPE diperbarui');
    }

    public function destroy(CpeParameterMap $cpeParameterMap): JsonResponse
    {
        $this->authorize('manage', CpeParameterMap::class);

        $cpeParameterMap->delete();

        return $this->success(null, 'Mapping parameter CPE dihapus');
    }

    /**
     * Stamps verified_at/verified_against_device_id using *right now* and
     * the device id the caller actually tested against — never accepted as
     * plain input on store/update, so a row can only become "verified" by
     * genuinely being checked through this endpoint (or the seeder, for the
     * one row seeded directly from this sprint's own real hardware test).
     */
    public function markVerified(Request $request, CpeParameterMap $cpeParameterMap): JsonResponse
    {
        $this->authorize('manage', CpeParameterMap::class);

        $request->validate([
            'device_id' => ['required', 'string'],
        ]);

        $cpeParameterMap->update([
            'verified_at' => now(),
            'verified_against_device_id' => $request->string('device_id'),
        ]);

        return $this->success(new CpeParameterMapResource($cpeParameterMap), 'Mapping parameter CPE ditandai terverifikasi');
    }

    /**
     * Resolves every mapped parameter for a real GenieACS device right now
     * — the actual end-to-end proof this whole module works, not just that
     * rows exist in the catalog.
     */
    public function resolve(string $genieacsDeviceId, CpeParameterResolverService $resolver): JsonResponse
    {
        $this->authorize('view', CpeParameterMap::class);

        return $this->success($resolver->resolveForDevice($genieacsDeviceId), 'Hasil resolve parameter CPE');
    }
}
