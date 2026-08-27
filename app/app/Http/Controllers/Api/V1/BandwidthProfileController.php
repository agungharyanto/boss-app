<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBandwidthProfileRequest;
use App\Http\Requests\UpdateBandwidthProfileRequest;
use App\Http\Resources\BandwidthProfileResource;
use App\Models\BandwidthProfile;
use App\Services\Network\BandwidthProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BandwidthProfileController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BandwidthProfile::class);

        $profiles = BandwidthProfile::query()
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy($request->string('sort_by', 'name'), $request->string('sort_dir', 'asc'))
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            BandwidthProfileResource::collection($profiles->items()),
            'Daftar bandwidth profile',
            [
                'pagination' => [
                    'current_page' => $profiles->currentPage(),
                    'per_page' => $profiles->perPage(),
                    'total' => $profiles->total(),
                    'last_page' => $profiles->lastPage(),
                ],
            ]
        );
    }

    public function store(StoreBandwidthProfileRequest $request, BandwidthProfileService $service): JsonResponse
    {
        $profile = $service->create($request->validated());

        return $this->success(new BandwidthProfileResource($profile), 'Bandwidth profile berhasil dibuat', [], 201);
    }

    public function show(BandwidthProfile $bandwidth_profile): JsonResponse
    {
        $this->authorize('view', $bandwidth_profile);

        return $this->success(new BandwidthProfileResource($bandwidth_profile));
    }

    public function update(UpdateBandwidthProfileRequest $request, BandwidthProfile $bandwidth_profile, BandwidthProfileService $service): JsonResponse
    {
        $profile = $service->update($bandwidth_profile, $request->validated());

        return $this->success(new BandwidthProfileResource($profile), 'Bandwidth profile berhasil diperbarui');
    }

    public function destroy(BandwidthProfile $bandwidth_profile, BandwidthProfileService $service): JsonResponse
    {
        $this->authorize('manage', BandwidthProfile::class);

        $service->delete($bandwidth_profile);

        return $this->success(null, 'Bandwidth profile berhasil dihapus');
    }
}
