<?php

namespace App\Livewire\Billing;

use App\Models\Customer;
use App\Models\Subscription;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

class SubscriptionIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public bool $showCreateForm = false;

    public string $customer_id = '';

    public string $name = '';

    public string $monthly_amount = '';

    public string $reseller_package_pricing_id = '';

    public string $billing_cycle_day = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Subscription::class);
    }

    public function createSubscription(SubscriptionService $service): void
    {
        $this->authorize('create', Subscription::class);

        // Explicit rules (not #[Validate] attributes) — name/monthly_amount
        // are only required when reseller_package_pricing_id is blank, and
        // relying on bare $this->validate() to correctly cross-reference a
        // plain (non-#[Validate]) property for required_without has bitten
        // us before (see TaxComponentIndex's mini-form bug).
        $hasPricing = $this->reseller_package_pricing_id !== '';

        $data = $this->validate([
            'customer_id' => 'required|exists:customers,id',
            'name' => $hasPricing ? 'nullable|string|max:255' : 'required|string|max:255',
            'monthly_amount' => $hasPricing ? 'nullable|numeric|min:0' : 'required|numeric|min:0',
            'billing_cycle_day' => 'required|integer|min:1|max:31',
        ]);

        $customer = Customer::findOrFail($data['customer_id']);
        unset($data['customer_id']);
        $data['reseller_package_pricing_id'] = $hasPricing ? $this->reseller_package_pricing_id : null;

        $service->create($customer, $data);

        $this->reset(['customer_id', 'name', 'monthly_amount', 'reseller_package_pricing_id', 'billing_cycle_day', 'showCreateForm']);
    }

    public function suspend(int $id, SubscriptionService $service): void
    {
        $subscription = Subscription::findOrFail($id);
        $this->authorize('update', $subscription);
        $service->suspend($subscription);
    }

    public function reactivate(int $id, SubscriptionService $service): void
    {
        $subscription = Subscription::findOrFail($id);
        $this->authorize('update', $subscription);
        $service->reactivate($subscription);
    }

    public function cancelSubscription(int $id, SubscriptionService $service): void
    {
        $subscription = Subscription::findOrFail($id);
        $this->authorize('update', $subscription);
        $service->cancel($subscription);
    }

    public function generateInvoiceNow(int $id, InvoiceService $service): void
    {
        $subscription = Subscription::findOrFail($id);
        $this->authorize('update', $subscription);
        $service->generateNextForSubscription($subscription);
    }

    public function render()
    {
        return view('livewire.billing.subscription-index', [
            'subscriptions' => Subscription::query()->with(['customer', 'reseller'])->latest()->paginate(15),
            'customers' => Customer::orderBy('name')->limit(200)->get(),
            'canCreate' => auth()->user()->can('create', Subscription::class),
        ]);
    }
}
