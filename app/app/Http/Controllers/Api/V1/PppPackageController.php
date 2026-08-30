<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePppPackageRequest;
use App\Http\Requests\UpdatePppPackageRequest;
use App\Http\Resources\PppPackageResource;
use App\Models\PppPackage;
use App\Services\Network\PppPackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PppPackageController extends Controller
{
    use ApiResponds;

    private const WITH = ['networkProfileGroup:id,name,type,nas_id', 'networkProfileGroup.nas:id,name', 'bandwidthProfile:id,name'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PppPackage::class);

        $packages = PppPackage::query()
            ->with(self::WITH)
            ->when($request->filled('network_profile_group_id'), fn ($query) => $query->where('network_profile_group_id', $request->integer('network_profile_group_id')))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy($request->string('sort_by', 'name'), $request->string('sort_dir', 'asc'))
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            PppPackageResource::collection($packages->items()),
            'Daftar profil PPP',
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

    public function store(StorePppPackageRequest $request, PppPackageService $service): JsonResponse
    {
        $package = $service->create($request->validated());

        return $this->success(new PppPackageResource($package->load(self::WITH)), 'Profil PPP berhasil dibuat', [], 201);
    }

    public function show(PppPackage $ppp_package): JsonResponse
    {
        $this->authorize('view', $ppp_package);

        return $this->success(new PppPackageResource($ppp_package->load(self::WITH)));
    }

    public function update(UpdatePppPackageRequest $request, PppPackage $ppp_package, PppPackageService $service): JsonResponse
    {
        $package = $service->update($ppp_package, $request->validated());

        return $this->success(new PppPackageResource($package->load(self::WITH)), 'Profil PPP berhasil diperbarui');
    }

    public function destroy(PppPackage $ppp_package, PppPackageService $service): JsonResponse
    {
        $this->authorize('manage', PppPackage::class);

        $service->delete($ppp_package);

        return $this->success(null, 'Profil PPP berhasil dihapus');
    }

    /**
     * v0.14.5 — manual "Sync Ulang", same pattern as HotspotPackageController.
     */
    public function resync(PppPackage $ppp_package, PppPackageService $service): JsonResponse
    {
        $this->authorize('manage', PppPackage::class);

        $service->resync($ppp_package);

        return $this->success(new PppPackageResource($ppp_package->load(self::WITH)), 'Sinkronisasi ke router dijadwalkan ulang');
    }
}
