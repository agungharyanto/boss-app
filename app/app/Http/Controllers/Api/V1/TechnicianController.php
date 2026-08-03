<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTechnicianRequest;
use App\Http\Resources\TechnicianResource;
use App\Models\Technician;
use App\Support\ResellerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TechnicianController extends Controller
{
    use ApiResponds;

    /**
     * BelongsToResellerScope narrows this to the reseller's own
     * technicians; an ISP admin (no context) sees every technician.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Technician::class);

        $technicians = Technician::query()
            ->with('reseller')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            TechnicianResource::collection($technicians->items()),
            'Daftar teknisi',
            ['pagination' => [
                'current_page' => $technicians->currentPage(),
                'per_page' => $technicians->perPage(),
                'total' => $technicians->total(),
                'last_page' => $technicians->lastPage(),
            ]]
        );
    }

    public function store(StoreTechnicianRequest $request, ResellerContext $context): JsonResponse
    {
        $data = $request->validated();
        $resellerId = $context->hasReseller() ? $context->reseller()->id : ($data['reseller_id'] ?? null);
        unset($data['reseller_id']);

        $technician = Technician::create([
            ...$data,
            'tenant_id' => $request->user()->tenant_id,
            'reseller_id' => $resellerId,
        ]);

        return $this->success(new TechnicianResource($technician), 'Teknisi berhasil dibuat', [], 201);
    }

    public function show(Technician $technician): JsonResponse
    {
        $this->authorize('view', $technician);

        return $this->success(new TechnicianResource($technician->load('reseller')));
    }
}
