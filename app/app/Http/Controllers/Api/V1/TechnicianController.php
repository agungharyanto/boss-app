<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTechnicianRequest;
use App\Http\Resources\TechnicianResource;
use App\Models\Technician;
use App\Services\Installation\TechnicianTokenService;
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

    /**
     * v0.12.3 — terbitkan token Sanctum baru untuk Technician-scoped API
     * (revoke SEMUA token lama miliknya). `manage` (bukan `view`) —
     * penerbitan token adalah aksi administratif, sama tingkat otorisasi
     * dengan edit/kelola data teknisi itu sendiri.
     *
     * Token plaintext HANYA muncul di response INI, sekali — Sanctum
     * sendiri cuma menyimpan hash-nya di `personal_access_tokens`, tidak
     * ada cara melihatnya lagi setelah response ini hilang. Simpan sekarang.
     */
    public function generateToken(Technician $technician, TechnicianTokenService $service): JsonResponse
    {
        $this->authorize('manage', $technician);

        // TechnicianTokenException (technician nonaktif / user tidak ada)
        // di-render otomatis jadi 422 oleh exception handler-nya sendiri —
        // tidak perlu try/catch manual di sini, sama pola
        // InvalidInvoiceStatusTransitionException.
        $token = $service->generate($technician);

        return $this->success(
            ['token' => $token],
            'Token diterbitkan — plaintext ini TIDAK akan ditampilkan lagi, simpan sekarang.',
        );
    }
}
