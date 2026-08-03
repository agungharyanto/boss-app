<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ResellerUserRole;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\AttachResellerUserRequest;
use App\Http\Resources\ResellerUserResource;
use App\Models\Reseller;
use App\Models\User;
use App\Services\ResellerService;
use Illuminate\Http\JsonResponse;

class ResellerUserController extends Controller
{
    use ApiResponds;

    public function index(Reseller $reseller, ResellerService $service): JsonResponse
    {
        $this->authorize('manageUsers', $reseller);

        return $this->success(ResellerUserResource::collection($service->listUsers($reseller)), 'Daftar staff reseller');
    }

    public function store(AttachResellerUserRequest $request, Reseller $reseller, ResellerService $service): JsonResponse
    {
        $user = User::where('tenant_id', $reseller->tenant_id)->findOrFail($request->validated('user_id'));

        $service->attachUser($reseller, $user, ResellerUserRole::from($request->validated('role')));

        return $this->success(ResellerUserResource::collection($service->listUsers($reseller)), 'Staff berhasil ditambahkan', [], 201);
    }

    public function destroy(Reseller $reseller, User $user, ResellerService $service): JsonResponse
    {
        $this->authorize('manageUsers', $reseller);

        $service->detachUser($reseller, $user);

        return $this->success(null, 'Staff berhasil dinonaktifkan dari reseller');
    }
}
