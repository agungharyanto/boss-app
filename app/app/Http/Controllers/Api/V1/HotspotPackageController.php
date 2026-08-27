<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHotspotPackageRequest;
use App\Http\Requests\UpdateHotspotPackageRequest;
use App\Http\Resources\HotspotPackageResource;
use App\Models\HotspotPackage;
use App\Services\Network\HotspotPackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HotspotPackageController extends Controller
{
    use ApiResponds;

    private const WITH = ['networkProfileGroup:id,name,type,nas_id', 'networkProfileGroup.nas:id,name', 'bandwidthProfile:id,name'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', HotspotPackage::class);

        $packages = HotspotPackage::query()
            ->with(self::WITH)
            ->when($request->filled('network_profile_group_id'), fn ($query) => $query->where('network_profile_group_id', $request->integer('network_profile_group_id')))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy($request->string('sort_by', 'name'), $request->string('sort_dir', 'asc'))
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            HotspotPackageResource::collection($packages->items()),
            'Daftar profil hotspot',
            [
                'pagination' => [
                    'current_page' => $packages->currentPage(),
                    'per_page' => $packages->perPage(),
                    'total' => $packages->total(),
                    'last_page' => $packages->lastPage(),
                ],
            ]
        );
    }

    public function store(StoreHotspotPackageRequest $request, HotspotPackageService $service): JsonResponse
    {
        $package = $service->create($request->validated());

        return $this->success(new HotspotPackageResource($package->load(self::WITH)), 'Profil hotspot berhasil dibuat', [], 201);
    }

    public function show(HotspotPackage $hotspot_package): JsonResponse
    {
        $this->authorize('view', $hotspot_package);

        return $this->success(new HotspotPackageResource($hotspot_package->load(self::WITH)));
    }

    public function update(UpdateHotspotPackageRequest $request, HotspotPackage $hotspot_package, HotspotPackageService $service): JsonResponse
    {
        $package = $service->update($hotspot_package, $request->validated());

        return $this->success(new HotspotPackageResource($package->load(self::WITH)), 'Profil hotspot berhasil diperbarui');
    }

    public function destroy(HotspotPackage $hotspot_package, HotspotPackageService $service): JsonResponse
    {
        $this->authorize('manage', HotspotPackage::class);

        $service->delete($hotspot_package);

        return $this->success(null, 'Profil hotspot berhasil dihapus');
    }

    /**
     * v0.14.4 — manual "Sync Ulang", same pattern as
     * CustomerIpPoolController/NetworkProfileGroupController.
     */
    public function resync(HotspotPackage $hotspot_package, HotspotPackageService $service): JsonResponse
    {
        $this->authorize('manage', HotspotPackage::class);

        $service->resync($hotspot_package);

        return $this->success(new HotspotPackageResource($hotspot_package->load(self::WITH)), 'Sinkronisasi ke router dijadwalkan ulang');
    }
}
