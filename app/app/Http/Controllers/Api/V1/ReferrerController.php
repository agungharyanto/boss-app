<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\LinkReferrerUserRequest;
use App\Http\Requests\StoreReferrerRequest;
use App\Http\Requests\UpdateReferrerRequest;
use App\Http\Resources\ReferrerResource;
use App\Models\Referrer;
use App\Models\User;
use App\Services\ReferrerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ReferrerController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Referrer::class);

        $referrers = Referrer::query()
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            ReferrerResource::collection($referrers->items()),
            'Daftar referrer',
            [
                'pagination' => [
                    'current_page' => $referrers->currentPage(),
                    'per_page' => $referrers->perPage(),
                    'total' => $referrers->total(),
                    'last_page' => $referrers->lastPage(),
                ],
            ]
        );
    }

    public function store(StoreReferrerRequest $request, ReferrerService $service): JsonResponse
    {
        $data = $request->validated();
        $createLoginAccount = $request->boolean('create_login_account');

        $result = $service->create($data, $createLoginAccount);

        return $this->success(
            [
                'referrer' => new ReferrerResource($result['referrer']),
                'generated_password' => $result['generated_password'],
            ],
            'Referrer berhasil dibuat',
            [],
            201
        );
    }

    public function show(Referrer $referrer): JsonResponse
    {
        $this->authorize('view', $referrer);

        return $this->success(new ReferrerResource($referrer->load('user')));
    }

    public function update(UpdateReferrerRequest $request, Referrer $referrer, ReferrerService $service): JsonResponse
    {
        $referrer = $service->update($referrer, $request->validated());

        return $this->success(new ReferrerResource($referrer), 'Referrer berhasil diperbarui');
    }

    public function deactivate(Referrer $referrer, ReferrerService $service): JsonResponse
    {
        $this->authorize('manage', Referrer::class);

        $referrer = $service->deactivate($referrer);

        return $this->success(new ReferrerResource($referrer), 'Referrer berhasil dinonaktifkan');
    }

    public function generateLoginAccount(Referrer $referrer, ReferrerService $service): JsonResponse
    {
        $this->authorize('manage', Referrer::class);

        try {
            $result = $service->generateLoginAccount($referrer);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['referrer' => $e->getMessage()]);
        }

        return $this->success([
            'referrer' => new ReferrerResource($result['referrer']),
            'generated_password' => $result['generated_password'],
        ], 'Akun login berhasil dibuat');
    }

    public function linkUser(LinkReferrerUserRequest $request, Referrer $referrer, ReferrerService $service): JsonResponse
    {
        $user = User::where('tenant_id', $request->user()->tenant_id)->findOrFail($request->validated('user_id'));

        try {
            $referrer = $service->linkExistingUser($referrer, $user);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['user_id' => $e->getMessage()]);
        }

        return $this->success(new ReferrerResource($referrer->load('user')), 'User berhasil dihubungkan sebagai akun login referrer');
    }
}
