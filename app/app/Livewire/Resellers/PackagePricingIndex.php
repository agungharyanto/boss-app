<?php

namespace App\Livewire\Resellers;

use App\Models\ResellerPackagePricing;
use App\Services\ResellerPackagePricingService;
use App\Support\ResellerContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reseller-facing: a reseller owner/staff manages pricing for their OWN
 * reseller only (attribution comes from App\Support\ResellerContext, set by
 * App\Http\Middleware\ResolveResellerContext on the route). An ISP admin
 * visiting this same page (no reseller context resolved for them) gets a
 * read-only, cross-reseller view — creating/editing pricing on a specific
 * reseller's behalf as an admin is an API-only capability for this sprint
 * (StoreResellerPackagePricingRequest's explicit reseller_id path), not
 * built into this thin UI.
 */
class PackagePricingIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public bool $showCreateForm = false;

    public ?int $editingId = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('required|numeric|min:0')]
    public string $price = '';

    public bool $isCustom = false;

    public function mount(): void
    {
        $this->authorize('viewAny', ResellerPackagePricing::class);
    }

    public function edit(int $id): void
    {
        $pricing = ResellerPackagePricing::findOrFail($id);
        $this->authorize('update', $pricing);

        $this->editingId = $id;
        $this->name = $pricing->name;
        $this->description = (string) $pricing->description;
        $this->price = (string) $pricing->price;
        $this->isCustom = $pricing->is_custom;
        $this->showCreateForm = true;
    }

    public function save(ResellerPackagePricingService $service, ResellerContext $context): void
    {
        $data = $this->validate();
        $data['description'] = $data['description'] ?: null;
        $data['is_custom'] = $this->isCustom;

        if ($this->editingId) {
            $pricing = ResellerPackagePricing::findOrFail($this->editingId);
            $this->authorize('update', $pricing);
            $service->updatePackage($pricing, $data);
        } else {
            $this->authorize('create', ResellerPackagePricing::class);

            $reseller = $context->reseller();
            abort_if($reseller === null, 403, __('Hanya reseller owner/staff yang bisa membuat package pricing lewat halaman ini.'));

            $service->createPackage($reseller, $data);
        }

        $this->cancelForm();
    }

    public function deactivate(int $id, ResellerPackagePricingService $service): void
    {
        $pricing = ResellerPackagePricing::findOrFail($id);
        $this->authorize('update', $pricing);

        $service->deactivatePackage($pricing);
    }

    public function cancelForm(): void
    {
        $this->reset(['name', 'description', 'price', 'isCustom', 'showCreateForm', 'editingId']);
    }

    public function render()
    {
        return view('livewire.resellers.package-pricing-index', [
            'pricingList' => ResellerPackagePricing::query()->with('reseller')->latest()->paginate(15),
            'canCreate' => auth()->user()->can('create', ResellerPackagePricing::class),
        ]);
    }
}
