<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Customers\CreateCustomerAction;
use App\Actions\Customers\UpdateCustomerAction;
use App\Actions\Customers\UpdateCustomerStatusAction;
use App\Enums\CustomerStatus;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Requests\UpdateCustomerStatusRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $customers = Customer::query()
            ->with('authorizedContact')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            CustomerResource::collection($customers->items()),
            'Daftar pelanggan',
            [
                'pagination' => [
                    'current_page' => $customers->currentPage(),
                    'per_page' => $customers->perPage(),
                    'total' => $customers->total(),
                    'last_page' => $customers->lastPage(),
                ],
            ]
        );
    }

    public function store(StoreCustomerRequest $request, CreateCustomerAction $action): JsonResponse
    {
        $customer = $action->handle($request->validated());

        return $this->success(new CustomerResource($customer), 'Pelanggan berhasil dibuat', [], 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return $this->success(new CustomerResource($customer->load('authorizedContact')));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, UpdateCustomerAction $action): JsonResponse
    {
        $customer = $action->handle($customer, $request->validated());

        return $this->success(new CustomerResource($customer), 'Pelanggan berhasil diperbarui');
    }

    public function updateStatus(UpdateCustomerStatusRequest $request, Customer $customer, UpdateCustomerStatusAction $action): JsonResponse
    {
        $customer = $action->handle($customer, CustomerStatus::from($request->validated('status')));

        return $this->success(new CustomerResource($customer), 'Status pelanggan berhasil diubah');
    }
}
