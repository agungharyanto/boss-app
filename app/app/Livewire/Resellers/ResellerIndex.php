<?php

namespace App\Livewire\Resellers;

use App\Models\Reseller;
use App\Services\ResellerService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

class ResellerIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    public bool $showCreateForm = false;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|email|max:255')]
    public string $email = '';

    #[Validate('nullable|string|max:30')]
    public string $phone = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Reseller::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function createReseller(ResellerService $service): void
    {
        $this->authorize('create', Reseller::class);

        $data = $this->validate();
        $data = array_filter($data, fn ($value) => $value !== '');

        $service->createReseller($data);

        $this->reset(['name', 'email', 'phone', 'showCreateForm']);
    }

    public function suspendReseller(int $resellerId, ResellerService $service): void
    {
        $reseller = Reseller::findOrFail($resellerId);
        $this->authorize('update', $reseller);

        $service->suspendReseller($reseller);
    }

    public function render()
    {
        $resellers = Reseller::query()
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(15);

        return view('livewire.resellers.reseller-index', [
            'resellers' => $resellers,
            'canCreate' => auth()->user()->can('create', Reseller::class),
        ]);
    }
}
