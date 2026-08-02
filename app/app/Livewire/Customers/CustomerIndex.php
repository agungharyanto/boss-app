<?php

namespace App\Livewire\Customers;

use App\Actions\Customers\CreateCustomerAction;
use App\Models\Customer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

class CustomerIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public bool $showCreateForm = false;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string')]
    public string $address = '';

    #[Validate('required|string|max:20')]
    public string $phone_number = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Customer::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function createCustomer(CreateCustomerAction $action): void
    {
        $this->authorize('create', Customer::class);

        $data = $this->validate();

        $action->handle($data);

        $this->reset(['name', 'address', 'phone_number', 'showCreateForm']);
    }

    public function render()
    {
        $customers = Customer::query()
            ->when($this->search, fn ($query) => $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('phone_number', 'like', "%{$this->search}%");
            }))
            ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter))
            ->latest()
            ->paginate(15);

        return view('livewire.customers.customer-index', [
            'customers' => $customers,
            'canCreate' => auth()->user()->can('create', Customer::class),
            'canRegister' => auth()->user()->can('register-customer'),
        ]);
    }
}
