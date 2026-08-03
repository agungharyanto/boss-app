<?php

namespace App\Livewire\Tax;

use App\Models\TaxComponent;
use App\Services\Tax\TaxComponentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

class TaxComponentIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public bool $showCreateForm = false;

    #[Validate('required|string|max:50')]
    public string $code = '';

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|in:percentage,fixed')]
    public string $type = 'percentage';

    #[Validate('required|numeric|min:0')]
    public string $rate = '';

    #[Validate('required|date')]
    public string $effective_from = '';

    #[Validate('nullable|string')]
    public string $description = '';

    // update-rate mini-form, keyed to whichever row is being changed
    public ?int $updatingRateFor = null;

    #[Validate('required|numeric|min:0')]
    public string $newRate = '';

    #[Validate('required|date')]
    public string $newRateEffectiveFrom = '';

    public function mount(): void
    {
        $this->authorize('viewAny', TaxComponent::class);
        $this->effective_from = now()->startOfMonth()->toDateString();
    }

    public function createComponent(TaxComponentService $service): void
    {
        $this->authorize('create', TaxComponent::class);

        // Explicit rules — this class also has newRate/newRateEffectiveFrom
        // #[Validate] properties for the separate update-rate mini-form; a
        // bare $this->validate() would validate those too and fail here
        // while they're still empty.
        $data = $this->validate([
            'code' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'type' => 'required|in:percentage,fixed',
            'rate' => 'required|numeric|min:0',
            'effective_from' => 'required|date',
            'description' => 'nullable|string',
        ]);
        $data['description'] = $data['description'] ?: null;

        $service->create($data);

        $this->reset(['code', 'name', 'rate', 'description', 'showCreateForm']);
        $this->type = 'percentage';
        $this->effective_from = now()->startOfMonth()->toDateString();
    }

    public function startUpdateRate(int $id): void
    {
        $component = TaxComponent::findOrFail($id);
        $this->authorize('update', $component);

        $this->updatingRateFor = $id;
        $this->newRate = (string) $component->rate;
        $this->newRateEffectiveFrom = now()->addMonth()->startOfMonth()->toDateString();
    }

    public function submitUpdateRate(TaxComponentService $service): void
    {
        $component = TaxComponent::findOrFail($this->updatingRateFor);
        $this->authorize('update', $component);

        $this->validate([
            'newRate' => 'required|numeric|min:0',
            'newRateEffectiveFrom' => 'required|date',
        ]);

        $service->updateRate($component, (float) $this->newRate, Carbon::parse($this->newRateEffectiveFrom));

        $this->reset(['updatingRateFor', 'newRate', 'newRateEffectiveFrom']);
    }

    public function cancelUpdateRate(): void
    {
        $this->reset(['updatingRateFor', 'newRate', 'newRateEffectiveFrom']);
    }

    public function toggleActive(int $id, TaxComponentService $service): void
    {
        $component = TaxComponent::findOrFail($id);
        $this->authorize('update', $component);

        $service->toggleActive($component, ! $component->is_active);
    }

    public function render()
    {
        return view('livewire.tax.tax-component-index', [
            'components' => TaxComponent::query()->latest('effective_from')->paginate(15),
            'canCreate' => auth()->user()->can('create', TaxComponent::class),
        ]);
    }
}
