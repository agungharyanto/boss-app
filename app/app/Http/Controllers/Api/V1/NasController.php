<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\CoaDisconnectRequest;
use App\Http\Requests\ProvisionNasApiUserRequest;
use App\Http\Requests\StoreNasRequest;
use App\Http\Requests\UpdateExpiredProfileRequest;
use App\Http\Requests\UpdateNasRequest;
use App\Http\Resources\NasResource;
use App\Models\Nas;
use App\Services\Network\CoaService;
use App\Services\Network\Contracts\RouterOsGateway;
use App\Services\Network\NasApiUserProvisioningService;
use App\Services\Network\NasService;
use App\Support\ResellerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NasController extends Controller
{
    use ApiResponds;

    /**
     * BelongsToResellerScope narrows this to the reseller's own NAS; an ISP
     * admin (no context) sees every NAS including direct ones.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Nas::class);

        $nas = Nas::query()
            ->with('reseller')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            NasResource::collection($nas->items()),
            'Daftar NAS',
            ['pagination' => [
                'current_page' => $nas->currentPage(),
                'per_page' => $nas->perPage(),
                'total' => $nas->total(),
                'last_page' => $nas->lastPage(),
            ]]
        );
    }

    public function store(StoreNasRequest $request, ResellerContext $context, NasService $service): JsonResponse
    {
        $data = $request->validated();
        $resellerId = $context->hasReseller() ? $context->reseller()->id : ($data['reseller_id'] ?? null);
        unset($data['reseller_id']);

        $nas = $service->create($data, $request->user()->tenant_id, $resellerId);

        return $this->success(new NasResource($nas), 'NAS berhasil dibuat', [], 201);
    }

    public function show(Nas $nas): JsonResponse
    {
        $this->authorize('view', $nas);

        return $this->success(new NasResource($nas->load('reseller')));
    }

    public function update(UpdateNasRequest $request, Nas $nas, NasService $service): JsonResponse
    {
        $nas = $service->update($nas, $request->validated());

        return $this->success(new NasResource($nas), 'NAS berhasil diperbarui');
    }

    public function destroy(Nas $nas, NasService $service): JsonResponse
    {
        $this->authorize('manage', $nas);

        $service->delete($nas);

        return $this->success(null, 'NAS berhasil dihapus');
    }

    public function testConnection(Nas $nas, NasService $service): JsonResponse
    {
        $this->authorize('manage', $nas);

        $nas = $service->testConnection($nas);

        return $this->success(new NasResource($nas), 'Tes koneksi selesai');
    }

    /**
     * v0.6.5 — force-drops an active PPP/hotspot session on this NAS via
     * RADIUS Disconnect-Request (RFC 5176), e.g. isolir instan pelanggan
     * menunggak. See CoaService's own docblock for the OpenVPN/WireGuard-
     * only limitation and the multi-node-pool caveat.
     */
    public function disconnect(CoaDisconnectRequest $request, Nas $nas, CoaService $service): JsonResponse
    {
        $result = $service->disconnect($nas, $request->validated('username'));

        return $this->success($result, $result['ok'] ? 'Sesi berhasil diputus' : 'NAS menolak permintaan disconnect');
    }

    /**
     * v0.6.5 — one-time (per call) action: connects with the router's real
     * admin credential (never persisted, see ProvisionNasApiUserRequest's
     * own docblock) to create/replace a dedicated, restricted-policy API
     * user, then updates nas.api_username/api_password to that new
     * credential. Replaces the old behavior where generating the RADIUS
     * script silently rotated these on every call — see
     * NasApiUserProvisioningService's docblock for the full story.
     */
    public function provisionApiUser(ProvisionNasApiUserRequest $request, Nas $nas, NasApiUserProvisioningService $service): JsonResponse
    {
        $nas = $service->provisionWithAdminCredential(
            $nas,
            $request->validated('admin_username'),
            $request->validated('admin_password'),
        );

        return $this->success(new NasResource($nas), 'User API berhasil dibuat/diperbarui');
    }

    /**
     * Revisi Grup Profil (Langkah 3) — sets/clears this NAS's own "Profile
     * Pelanggan Expired" fallback pool; async RouterOS live-push dispatch
     * happens inside NasService::updateExpiredIpPool() itself, same
     * fire-and-forget posture as every other live-push endpoint in this
     * codebase.
     */
    public function updateExpiredProfile(UpdateExpiredProfileRequest $request, Nas $nas, NasService $service): JsonResponse
    {
        $nas = $service->updateExpiredIpPool($nas, $request->validated('customer_ip_pool_id'));

        return $this->success(new NasResource($nas), 'Profil Pelanggan Expired berhasil diperbarui');
    }

    /**
     * Revisi Grup Profil (Langkah 1) — READ-ONLY live listing of this
     * NAS's existing physical + VLAN interfaces, for the Grup Profil
     * "Interface/VLAN" dropdown. Never creates anything on the router —
     * see RouterOsGateway::listInterfaces()'s own docblock.
     */
    public function interfaces(Nas $nas, RouterOsGateway $gateway): JsonResponse
    {
        $this->authorize('view', $nas);

        // Same 30s-per-NAS cache as NetworkProfileGroupIndex's own
        // interfaceOptionsForNas() — a single source of truth for "don't
        // hammer the router on every dropdown open" would need a shared
        // service, judged unnecessary complexity for one cached call; both
        // sides use the identical cache key shape/TTL so a request through
        // either path benefits from whichever the other already primed.
        $interfaces = Cache::remember("nas:{$nas->id}:interfaces", 30, fn () => $gateway->listInterfaces($nas));

        return $this->success($interfaces, 'Daftar interface NAS');
    }
}
