<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerIpPoolRequest;
use App\Http\Requests\UpdateCustomerIpPoolRequest;
use App\Http\Resources\CustomerIpPoolResource;
use App\Models\CustomerIpPool;
use App\Services\Network\CustomerIpPoolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerIpPoolController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerIpPool::class);

        $pools = CustomerIpPool::query()
            ->with('nas:id,name')
            ->when($request->filled('nas_id'), fn ($query) => $query->where('nas_id', $request->integer('nas_id')))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy($request->string('sort_by', 'name'), $request->string('sort_dir', 'asc'))
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            CustomerIpPoolResource::collection($pools->items()),
            'Daftar customer IP pool',
            [
                'pagination' => [
                    'current_page' => $pools->currentPage(),
                    'per_page' => $pools->perPage(),
                    'total' => $pools->total(),
                    'last_page' => $pools->lastPage(),
                ],
            ]
        );
    }

    public function store(StoreCustomerIpPoolRequest $request, CustomerIpPoolService $service): JsonResponse
    {
        $pool = $service->create($request->validated());

        return $this->success(new CustomerIpPoolResource($pool->load('nas:id,name')), 'Customer IP pool berhasil dibuat', [], 201);
    }

    public function show(CustomerIpPool $customer_ip_pool): JsonResponse
    {
        $this->authorize('view', $customer_ip_pool);

        return $this->success(new CustomerIpPoolResource($customer_ip_pool->load('nas:id,name')));
    }

    public function update(UpdateCustomerIpPoolRequest $request, CustomerIpPool $customer_ip_pool, CustomerIpPoolService $service): JsonResponse
    {
        $pool = $service->update($customer_ip_pool, $request->validated());

        return $this->success(new CustomerIpPoolResource($pool->load('nas:id,name')), 'Customer IP pool berhasil diperbarui');
    }

    public function destroy(CustomerIpPool $customer_ip_pool, CustomerIpPoolService $service): JsonResponse
    {
        $this->authorize('manage', CustomerIpPool::class);

        $service->delete($customer_ip_pool);

        return $this->success(null, 'Customer IP pool berhasil dihapus');
    }
}
