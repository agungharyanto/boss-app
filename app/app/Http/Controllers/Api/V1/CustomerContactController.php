<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Customers\CreateCustomerContactAction;
use App\Actions\Customers\UpdateCustomerContactAction;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerContactRequest;
use App\Http\Requests\UpdateCustomerContactRequest;
use App\Http\Resources\CustomerContactResource;
use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Http\JsonResponse;

class CustomerContactController extends Controller
{
    use ApiResponds;

    public function index(Customer $customer): JsonResponse
    {
        $this->authorize('viewAny', CustomerContact::class);

        return $this->success(
            CustomerContactResource::collection($customer->contacts()->latest()->get()),
            'Daftar kontak keluarga'
        );
    }

    public function store(StoreCustomerContactRequest $request, Customer $customer, CreateCustomerContactAction $action): JsonResponse
    {
        $contact = $action->handle($customer, $request->validated());

        return $this->success(new CustomerContactResource($contact), 'Kontak berhasil ditambahkan', [], 201);
    }

    public function show(Customer $customer, CustomerContact $contact): JsonResponse
    {
        $this->authorize('view', $contact);
        $this->ensureContactBelongsToCustomer($customer, $contact);

        return $this->success(new CustomerContactResource($contact));
    }

    public function update(UpdateCustomerContactRequest $request, Customer $customer, CustomerContact $contact, UpdateCustomerContactAction $action): JsonResponse
    {
        $this->ensureContactBelongsToCustomer($customer, $contact);

        $contact = $action->handle($contact, $request->validated());

        return $this->success(new CustomerContactResource($contact), 'Kontak berhasil diperbarui');
    }

    public function destroy(Customer $customer, CustomerContact $contact): JsonResponse
    {
        $this->authorize('delete', $contact);
        $this->ensureContactBelongsToCustomer($customer, $contact);

        $contact->delete();

        return $this->success(null, 'Kontak berhasil dihapus');
    }

    private function ensureContactBelongsToCustomer(Customer $customer, CustomerContact $contact): void
    {
        abort_unless($contact->customer_id === $customer->id, 404);
    }
}
