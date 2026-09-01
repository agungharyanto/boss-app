<?php

namespace App\Livewire\Customers;

use App\Enums\ResellerUserStatus;
use App\Models\Customer;
use App\Models\ResellerUser;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * v0.16.0 Langkah 12 — "Lengkapi Koordinat Pelanggan". A manual bind
 * page (pattern mirrors the CPE "Cek Status Device" self-service tool):
 * list every customer with no coordinates yet, drop a pin per customer,
 * save straight to customers.latitude/longitude.
 *
 * DELIBERATELY does nothing else — no ODP link, no work order, no
 * OdpPort reservation. Filling a coordinate is purely enriching the
 * customer row so it can appear on the Peta Topologi "Pelanggan" layer
 * (Langkah 10). A formal customer→ODP relation, if ever wanted, is a
 * separate feature.
 *
 * Sits inside the /customers reseller.context group, so a reseller
 * staffer only ever sees (and can only bind) their own customers via
 * ResellerScope.
 */
class CustomerCoordinateFill extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    public ?int $editingCustomerId = null;

    public string $latitude = '';

    public string $longitude = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Customer::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function startEditing(int $customerId): void
    {
        $customer = Customer::findOrFail($customerId);
        $this->authorize('update', $customer);

        $this->editingCustomerId = $customer->id;
        $this->latitude = $customer->latitude !== null ? (string) $customer->latitude : '';
        $this->longitude = $customer->longitude !== null ? (string) $customer->longitude : '';
    }

    public function cancelEditing(): void
    {
        $this->reset('editingCustomerId', 'latitude', 'longitude');
    }

    public function saveLocation(): void
    {
        if ($this->editingCustomerId === null) {
            return;
        }

        $customer = Customer::findOrFail($this->editingCustomerId);
        $this->authorize('update', $customer);

        $this->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ], [], [
            'latitude' => 'Lokasi pelanggan',
            'longitude' => 'Lokasi pelanggan',
        ]);

        // Only ever these two columns — no relation is created or touched.
        $customer->update([
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
        ]);

        session()->flash('coordinate-status', 'Koordinat '.$customer->name.' disimpan.');
        $this->reset('editingCustomerId', 'latitude', 'longitude');
        $this->resetPage();
    }

    public function render()
    {
        $needle = trim($this->search);

        $customers = Customer::query()
            ->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))
            ->when($needle !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$needle}%")
                ->orWhere('cid', 'like', "%{$needle}%")
                ->orWhere('phone_number', 'like', "%{$needle}%")))
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.customers.customer-coordinate-fill', [
            'customers' => $customers,
            'canManage' => $this->canManage(),
        ]);
    }

    /**
     * Mirrors CustomerPolicy::update at the list level — internal staff
     * with customers.manage, or any active reseller staffer (whose
     * ResellerScope already limits this list to their own customers).
     */
    private function canManage(): bool
    {
        return auth()->user()->can('customers.manage')
            || ResellerUser::query()
                ->where('user_id', auth()->id())
                ->where('status', ResellerUserStatus::Active)
                ->exists();
    }
}
