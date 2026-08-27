<?php

namespace App\Livewire\Network;

use App\Enums\CustomerIpPoolUsageType;
use App\Enums\MikrotikSyncStatus;
use App\Enums\NetworkProfileGroupType;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Services\Network\NetworkProfileGroupService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * v0.14.3 — cluster "Profil Paket". A NAS-scoped RADIUS/Mikrotik profile
 * template (Hotspot/PPP), referencing a CustomerIpPool (v0.14.2) from the
 * SAME NAS. See NetworkProfileGroup's own docblock.
 */
class NetworkProfileGroupIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    public string $filterNasId = '';

    public string $sortBy = 'name';

    public string $sortDir = 'asc';

    public bool $showCreateForm = false;

    #[Validate('required|integer')]
    public string $nasId = '';

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string')]
    public string $type = 'ppp';

    #[Validate('required|integer')]
    public string $customerIpPoolId = '';

    public string $dnsPrimary = '';

    public string $dnsSecondary = '';

    public string $parentQueue = '';

    public bool $isActive = true;

    public ?int $editingGroupId = null;

    public string $editNasId = '';

    public string $editName = '';

    public string $editType = 'ppp';

    public string $editCustomerIpPoolId = '';

    public string $editDnsPrimary = '';

    public string $editDnsSecondary = '';

    public string $editParentQueue = '';

    public bool $editIsActive = true;

    public function mount(): void
    {
        $this->authorize('viewAny', NetworkProfileGroup::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterNasId(): void
    {
        $this->resetPage();
    }

    /**
     * Switching NAS in the create form invalidates whichever IP Pool was
     * selected — it almost certainly doesn't belong to the newly-picked
     * NAS anymore (same "invalidate on the field it depends on changing"
     * discipline already established by OltDeviceIndex's own
     * testPassedForKey mechanism, v0.8.1).
     */
    public function updatedNasId(): void
    {
        $this->customerIpPoolId = '';
    }

    public function updatedEditNasId(): void
    {
        $this->editCustomerIpPoolId = '';
    }

    /**
     * v0.14.3.1 — real bug found by Agung: the pool dropdown could show a
     * pool clearly meant for the other type (e.g. "Hotspot-10Mbps" while
     * Tipe=PPP). Switching Tipe invalidates the selected pool the same way
     * switching NAS already does — it may no longer be compatible with the
     * newly-picked type.
     */
    public function updatedType(): void
    {
        $this->customerIpPoolId = '';
    }

    public function updatedEditType(): void
    {
        $this->editCustomerIpPoolId = '';
    }

    public function sortByColumn(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRules(string $nasField, string $nameField, ?int $ignoreId): array
    {
        return [
            $nasField => ['required', 'integer'],
            $nameField => [
                'required', 'string', 'max:255',
                Rule::unique(NetworkProfileGroup::class, 'name')
                    ->where('nas_id', $this->{$nasField})
                    ->whereNull('deleted_at')
                    ->when($ignoreId, fn ($rule) => $rule->ignore($ignoreId)),
            ],
        ];
    }

    /**
     * Mirrors StoreNetworkProfileGroupRequest/UpdateNetworkProfileGroupRequest's
     * own validatePoolBelongsToSameNas()/validatePoolUsageTypeMatches() —
     * Livewire's inline validate() array can't express a validator-level
     * "after" hook the same way. Also covers what those FormRequests get
     * for free from their own Rule::exists()->whereNull('deleted_at')
     * (this component's baseRules() only checks required|integer for the
     * pool field, not existence) — a plain SCOPED find() (not
     * withoutGlobalScopes()) is what correctly excludes an already-
     * soft-deleted pool here, same real bug/fix as both FormRequests.
     */
    private function validatePoolBelongsToSameNas(string $nasId, string $poolId, string $type, string $errorField): bool
    {
        $pool = CustomerIpPool::find((int) $poolId);

        if ($pool === null) {
            $this->addError($errorField, 'IP Pool yang dipilih tidak ditemukan.');

            return false;
        }

        if ($pool->nas_id !== (int) $nasId) {
            $this->addError($errorField, 'IP Pool yang dipilih harus milik NAS yang sama.');

            return false;
        }

        // v0.14.3.1 — backend enforcement of the same rule the dropdown's
        // own filter already applies, never relying on the frontend
        // filter alone.
        $groupType = NetworkProfileGroupType::from($type);

        if (! $pool->usage_type->isCompatibleWith($groupType)) {
            $this->addError($errorField, "IP Pool ini bertipe pemakaian \"{$pool->usage_type->label()}\", tidak cocok untuk Grup Profil tipe \"{$groupType->label()}\".");

            return false;
        }

        return true;
    }

    public function createGroup(NetworkProfileGroupService $service): void
    {
        $this->authorize('manage', NetworkProfileGroup::class);

        $this->name = trim($this->name);

        $this->validate(array_merge($this->baseRules('nasId', 'name', null), [
            'type' => ['required', 'string', 'in:hotspot,ppp'],
            'customerIpPoolId' => ['required', 'integer'],
            'dnsPrimary' => ['nullable', 'ip'],
            'dnsSecondary' => ['nullable', 'ip'],
            'parentQueue' => ['nullable', 'string', 'max:255'],
        ]));

        if (! $this->validatePoolBelongsToSameNas($this->nasId, $this->customerIpPoolId, $this->type, 'customerIpPoolId')) {
            return;
        }

        $service->create([
            'nas_id' => (int) $this->nasId,
            'name' => $this->name,
            'type' => $this->type,
            'customer_ip_pool_id' => (int) $this->customerIpPoolId,
            'dns_primary' => $this->dnsPrimary ?: null,
            'dns_secondary' => $this->dnsSecondary ?: null,
            'parent_queue' => $this->parentQueue ?: null,
            'is_active' => $this->isActive,
        ]);

        $this->reset(['nasId', 'name', 'type', 'customerIpPoolId', 'dnsPrimary', 'dnsSecondary', 'parentQueue', 'isActive', 'showCreateForm']);
        $this->type = 'ppp';
        $this->isActive = true;
    }

    public function edit(int $groupId): void
    {
        $group = NetworkProfileGroup::findOrFail($groupId);
        $this->authorize('manage', NetworkProfileGroup::class);

        $this->editingGroupId = $group->id;
        $this->editNasId = (string) $group->nas_id;
        $this->editName = $group->name;
        $this->editType = $group->type->value;
        $this->editCustomerIpPoolId = (string) $group->customer_ip_pool_id;
        $this->editDnsPrimary = (string) $group->dns_primary;
        $this->editDnsSecondary = (string) $group->dns_secondary;
        $this->editParentQueue = (string) $group->parent_queue;
        $this->editIsActive = $group->is_active;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingGroupId', 'editNasId', 'editName', 'editType', 'editCustomerIpPoolId', 'editDnsPrimary', 'editDnsSecondary', 'editParentQueue', 'editIsActive']);
    }

    public function updateGroup(NetworkProfileGroupService $service): void
    {
        $group = NetworkProfileGroup::findOrFail($this->editingGroupId);
        $this->authorize('manage', NetworkProfileGroup::class);

        $this->editName = trim($this->editName);

        $this->validate(array_merge($this->baseRules('editNasId', 'editName', $group->id), [
            'editType' => ['required', 'string', 'in:hotspot,ppp'],
            'editCustomerIpPoolId' => ['required', 'integer'],
            'editDnsPrimary' => ['nullable', 'ip'],
            'editDnsSecondary' => ['nullable', 'ip'],
            'editParentQueue' => ['nullable', 'string', 'max:255'],
        ]));

        if (! $this->validatePoolBelongsToSameNas($this->editNasId, $this->editCustomerIpPoolId, $this->editType, 'editCustomerIpPoolId')) {
            return;
        }

        $service->update($group, [
            'nas_id' => (int) $this->editNasId,
            'name' => $this->editName,
            'type' => $this->editType,
            'customer_ip_pool_id' => (int) $this->editCustomerIpPoolId,
            'dns_primary' => $this->editDnsPrimary ?: null,
            'dns_secondary' => $this->editDnsSecondary ?: null,
            'parent_queue' => $this->editParentQueue ?: null,
            'is_active' => $this->editIsActive,
        ]);

        $this->cancelEdit();
    }

    public function deleteGroup(int $groupId, NetworkProfileGroupService $service): void
    {
        $this->authorize('manage', NetworkProfileGroup::class);

        $service->delete(NetworkProfileGroup::findOrFail($groupId));
    }

    public function resyncGroup(int $groupId, NetworkProfileGroupService $service): void
    {
        $this->authorize('manage', NetworkProfileGroup::class);

        $service->resync(NetworkProfileGroup::findOrFail($groupId));
    }

    public function render()
    {
        $groups = NetworkProfileGroup::query()
            ->with(['nas:id,name', 'customerIpPool:id,name'])
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->when($this->filterNasId, fn ($query) => $query->where('nas_id', $this->filterNasId))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(15);

        return view('livewire.network.network-profile-group-index', [
            'groups' => $groups,
            // Same conditional wire:poll pattern as CustomerIpPoolIndex
            // (v0.14.2.2) — reused, not reinvented.
            'hasPendingSync' => $groups->contains(fn (NetworkProfileGroup $group) => $group->mikrotik_sync_status === MikrotikSyncStatus::Pending),
            'nasOptions' => Nas::query()->orderBy('name')->get(['id', 'name']),
            // Filtered per the CURRENTLY selected NAS AND Tipe in each
            // form — computed here (not a separate Livewire computed()/
            // query call) since render() already runs on every relevant
            // update (NAS OR Tipe changing both trigger a re-render via
            // wire:model.live). A "general" pool is shown for either Tipe
            // (see CustomerIpPoolUsageType::isCompatibleWith()) — the
            // whereIn() below mirrors that same rule at the query level.
            'poolOptionsForNas' => $this->nasId !== ''
                ? CustomerIpPool::query()->where('nas_id', $this->nasId)->whereIn('usage_type', [$this->type, CustomerIpPoolUsageType::General->value])->orderBy('name')->get(['id', 'name'])
                : collect(),
            'editPoolOptionsForNas' => $this->editNasId !== ''
                ? CustomerIpPool::query()->where('nas_id', $this->editNasId)->whereIn('usage_type', [$this->editType, CustomerIpPoolUsageType::General->value])->orderBy('name')->get(['id', 'name'])
                : collect(),
            'canManage' => auth()->user()->can('manage', NetworkProfileGroup::class),
        ]);
    }
}
