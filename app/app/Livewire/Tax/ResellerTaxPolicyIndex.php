<?php

namespace App\Livewire\Tax;

use App\Models\Reseller;
use App\Models\ResellerTaxPolicy;
use App\Models\TaxComponent;
use App\Services\Tax\ResellerTaxPolicyService;
use App\Support\ResellerContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

class ResellerTaxPolicyIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public bool $showCreateForm = false;

    /**
     * Admin-only field: which reseller to set the policy for. Empty string
     * means "direct-retail" (reseller_id null) — the default when an admin
     * opens the form. Reseller owner/staff never see this field; their own
     * ResellerContext always decides the target.
     */
    public string $targetResellerId = '';

    #[Validate('required|exists:tax_components,id')]
    public string $tax_component_id = '';

    #[Validate('required|in:customer_borne,reseller_borne,split')]
    public string $burden = 'customer_borne';

    #[Validate('nullable|numeric|between:0,100')]
    public string $split_ratio = '';

    #[Validate('required|date')]
    public string $effective_from = '';

    public function mount(): void
    {
        $this->authorize('viewAny', ResellerTaxPolicy::class);
        $this->effective_from = now()->startOfMonth()->toDateString();
    }

    public function createPolicy(ResellerTaxPolicyService $service, ResellerContext $context): void
    {
        $isAdmin = auth()->user()->can('reseller_tax_policies.manage');
        $reseller = $isAdmin
            ? ($this->targetResellerId !== '' ? Reseller::find($this->targetResellerId) : null)
            : $context->reseller();

        $this->authorize('create', [ResellerTaxPolicy::class, $reseller]);

        $data = $this->validate();

        $component = TaxComponent::findOrFail($data['tax_component_id']);
        $splitRatio = $data['burden'] === 'split' ? (float) $data['split_ratio'] : null;

        try {
            $service->setPolicy($reseller, $component, $data['burden'], $splitRatio, Carbon::parse($data['effective_from']));
        } catch (InvalidArgumentException $e) {
            $this->addError('split_ratio', $e->getMessage());

            return;
        }

        $this->reset(['tax_component_id', 'split_ratio', 'showCreateForm', 'targetResellerId']);
        $this->burden = 'customer_borne';
        $this->effective_from = now()->startOfMonth()->toDateString();
    }

    public function render()
    {
        $context = app(ResellerContext::class);
        $isAdmin = auth()->user()->can('reseller_tax_policies.manage');

        $policies = ResellerTaxPolicy::query()
            ->with(['reseller', 'taxComponent'])
            ->when($context->hasReseller(), fn ($q) => $q->where('reseller_id', $context->reseller()->id))
            ->latest('effective_from')
            ->paginate(15);

        // Admin can always create (they pick the target reseller in the
        // form); a reseller-context user needs to specifically be an owner
        // of that reseller — staff is read-only (ResellerTaxPolicyPolicy).
        $canCreate = $isAdmin || ($context->hasReseller() && auth()->user()->can('create', [ResellerTaxPolicy::class, $context->reseller()]));

        return view('livewire.tax.reseller-tax-policy-index', [
            'policies' => $policies,
            'isAdmin' => $isAdmin,
            'canCreate' => $canCreate,
            'resellers' => $isAdmin ? Reseller::orderBy('name')->get() : collect(),
            'taxComponents' => TaxComponent::where('is_active', true)->orderBy('code')->get(),
            'currentReseller' => $context->reseller(),
        ]);
    }
}
