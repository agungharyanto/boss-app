<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNetworkProfileGroupRequest;
use App\Http\Requests\UpdateNetworkProfileGroupRequest;
use App\Http\Resources\NetworkProfileGroupResource;
use App\Models\NetworkProfileGroup;
use App\Services\Network\NetworkProfileGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NetworkProfileGroupController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', NetworkProfileGroup::class);

        $groups = NetworkProfileGroup::query()
            ->with(['nas:id,name', 'customerIpPool:id,name'])
            ->when($request->filled('nas_id'), fn ($query) => $query->where('nas_id', $request->integer('nas_id')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy($request->string('sort_by', 'name'), $request->string('sort_dir', 'asc'))
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            NetworkProfileGroupResource::collection($groups->items()),
            'Daftar grup profil',
            [
                'pagination' => [
                    'current_page' => $groups->currentPage(),
                    'per_page' => $groups->perPage(),
                    'total' => $groups->total(),
                    'last_page' => $groups->lastPage(),
                ],
            ]
        );
    }

    public function store(StoreNetworkProfileGroupRequest $request, NetworkProfileGroupService $service): JsonResponse
    {
        $group = $service->create($request->validated());

        return $this->success(new NetworkProfileGroupResource($group->load(['nas:id,name', 'customerIpPool:id,name'])), 'Grup profil berhasil dibuat', [], 201);
    }

    public function show(NetworkProfileGroup $network_profile_group): JsonResponse
    {
        $this->authorize('view', $network_profile_group);

        return $this->success(new NetworkProfileGroupResource($network_profile_group->load(['nas:id,name', 'customerIpPool:id,name'])));
    }

    public function update(UpdateNetworkProfileGroupRequest $request, NetworkProfileGroup $network_profile_group, NetworkProfileGroupService $service): JsonResponse
    {
        $group = $service->update($network_profile_group, $request->validated());

        return $this->success(new NetworkProfileGroupResource($group->load(['nas:id,name', 'customerIpPool:id,name'])), 'Grup profil berhasil diperbarui');
    }

    public function destroy(NetworkProfileGroup $network_profile_group, NetworkProfileGroupService $service): JsonResponse
    {
        $this->authorize('manage', NetworkProfileGroup::class);

        $service->delete($network_profile_group);

        return $this->success(null, 'Grup profil berhasil dihapus');
    }

    /**
     * v0.14.3 — manual "Sync Ulang", same pattern as CustomerIpPoolController.
     */
    public function resync(NetworkProfileGroup $network_profile_group, NetworkProfileGroupService $service): JsonResponse
    {
        $this->authorize('manage', NetworkProfileGroup::class);

        $service->resync($network_profile_group);

        return $this->success(new NetworkProfileGroupResource($network_profile_group->load(['nas:id,name', 'customerIpPool:id,name'])), 'Sinkronisasi ke router dijadwalkan ulang');
    }
}
