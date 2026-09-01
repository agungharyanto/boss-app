<?php

namespace App\Livewire\Network;

use App\Enums\MikrotikSyncStatus;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Services\Network\CustomerIpPoolService;
use App\Support\ProfilPaketAttributeLabels;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * v0.14.2 — cluster "Profil Paket". IP ranges allocated to a NAS's own
 * end-customer devices (hotspot/PPP) — a genuinely different concept from
 * the VPN tunnel IP pool (VpnIpPool, v0.8.1). See CustomerIpPool's own
 * docblock.
 */
class CustomerIpPoolIndex extends Component
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
    public string $networkAddress = '';

    #[Validate('required|ip')]
    public string $gatewayIp = '';

    #[Validate('required|ip')]
    public string $rangeStart = '';

    #[Validate('required|ip')]
    public string $rangeEnd = '';

    // v0.14.3.1 — required, no default here (see StoreCustomerIpPoolRequest's
    // own docblock: a NEW pool must be tagged deliberately).
    #[Validate('required|string|in:ppp,hotspot,general')]
    public string $usageType = '';

    public string $dnsPrimary = '';

    public string $dnsSecondary = '';

    public bool $isActive = true;

    public ?int $editingPoolId = null;

    public string $editNasId = '';

    public string $editName = '';

    public string $editNetworkAddress = '';

    public string $editGatewayIp = '';

    public string $editRangeStart = '';

    public string $editRangeEnd = '';

    public string $editUsageType = '';

    public string $editDnsPrimary = '';

    public string $editDnsSecondary = '';

    public bool $editIsActive = true;

    public function mount(): void
    {
        $this->authorize('viewAny', CustomerIpPool::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterNasId(): void
    {
        $this->resetPage();
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
                Rule::unique(CustomerIpPool::class, 'name')
                    ->where('nas_id', $this->{$nasField})
                    ->whereNull('deleted_at')
                    ->when($ignoreId, fn ($rule) => $rule->ignore($ignoreId)),
            ],
        ];
    }

    public function createPool(CustomerIpPoolService $service): void
    {
        $this->authorize('manage', CustomerIpPool::class);

        // Same trim-before-validate fix established in v0.14.1 —
        // Livewire's inline validate() doesn't go through FormRequest's
        // prepareForValidation() hook.
        $this->name = trim($this->name);

        $this->validate(array_merge($this->baseRules('nasId', 'name', null), [
            'usageType' => ['required', 'string', 'in:ppp,hotspot,general'],
            'networkAddress' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/'],
            'gatewayIp' => ['required', 'ip'],
            'rangeStart' => ['required', 'ip'],
            'rangeEnd' => ['required', 'ip'],
            'dnsPrimary' => ['nullable', 'ip'],
            'dnsSecondary' => ['nullable', 'ip'],
        ]));

        if (! $this->validateRangeAndCidr($service, $this->nasId, $this->networkAddress, $this->gatewayIp, $this->rangeStart, $this->rangeEnd, null, 'rangeEnd')) {
            return;
        }

        $service->create([
            'nas_id' => (int) $this->nasId,
            'name' => $this->name,
            'usage_type' => $this->usageType,
            'network_address' => $this->networkAddress,
            'gateway_ip' => $this->gatewayIp,
            'range_start' => $this->rangeStart,
            'range_end' => $this->rangeEnd,
            'dns_primary' => $this->dnsPrimary ?: null,
            'dns_secondary' => $this->dnsSecondary ?: null,
            'is_active' => $this->isActive,
        ]);

        $this->reset(['nasId', 'name', 'usageType', 'networkAddress', 'gatewayIp', 'rangeStart', 'rangeEnd', 'dnsPrimary', 'dnsSecondary', 'isActive', 'showCreateForm']);
        $this->isActive = true;
    }

    public function edit(int $poolId): void
    {
        $pool = CustomerIpPool::findOrFail($poolId);
        $this->authorize('manage', CustomerIpPool::class);

        $this->editingPoolId = $pool->id;
        $this->editNasId = (string) $pool->nas_id;
        $this->editName = $pool->name;
        $this->editUsageType = $pool->usage_type->value;
        $this->editNetworkAddress = $pool->network_address;
        $this->editGatewayIp = $pool->gateway_ip;
        $this->editRangeStart = $pool->range_start;
        $this->editRangeEnd = $pool->range_end;
        $this->editDnsPrimary = (string) $pool->dns_primary;
        $this->editDnsSecondary = (string) $pool->dns_secondary;
        $this->editIsActive = $pool->is_active;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingPoolId', 'editNasId', 'editName', 'editUsageType', 'editNetworkAddress', 'editGatewayIp', 'editRangeStart', 'editRangeEnd', 'editDnsPrimary', 'editDnsSecondary', 'editIsActive']);
    }

    public function updatePool(CustomerIpPoolService $service): void
    {
        $pool = CustomerIpPool::findOrFail($this->editingPoolId);
        $this->authorize('manage', CustomerIpPool::class);

        $this->editName = trim($this->editName);

        $this->validate(array_merge($this->baseRules('editNasId', 'editName', $pool->id), [
            'editUsageType' => ['required', 'string', 'in:ppp,hotspot,general'],
            'editNetworkAddress' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/'],
            'editGatewayIp' => ['required', 'ip'],
            'editRangeStart' => ['required', 'ip'],
            'editRangeEnd' => ['required', 'ip'],
            'editDnsPrimary' => ['nullable', 'ip'],
            'editDnsSecondary' => ['nullable', 'ip'],
        ]));

        if (! $this->validateRangeAndCidr($service, $this->editNasId, $this->editNetworkAddress, $this->editGatewayIp, $this->editRangeStart, $this->editRangeEnd, $pool->id, 'editRangeEnd')) {
            return;
        }

        $service->update($pool, [
            'nas_id' => (int) $this->editNasId,
            'name' => $this->editName,
            'usage_type' => $this->editUsageType,
            'network_address' => $this->editNetworkAddress,
            'gateway_ip' => $this->editGatewayIp,
            'range_start' => $this->editRangeStart,
            'range_end' => $this->editRangeEnd,
            'dns_primary' => $this->editDnsPrimary ?: null,
            'dns_secondary' => $this->editDnsSecondary ?: null,
            'is_active' => $this->editIsActive,
        ]);

        $this->cancelEdit();
    }

    /**
     * Shared range-order / CIDR-containment / overlap checks for both
     * createPool() and updatePool() — mirrors StoreCustomerIpPoolRequest/
     * UpdateCustomerIpPoolRequest's own withValidator() logic since
     * Livewire's inline validate() array can't express a validator-level
     * "after" hook the same way a FormRequest can.
     */
    private function validateRangeAndCidr(CustomerIpPoolService $service, string $nasId, string $cidr, string $gatewayIp, string $rangeStart, string $rangeEnd, ?int $ignorePoolId, string $endErrorField): bool
    {
        $startLong = ip2long($rangeStart);
        $endLong = ip2long($rangeEnd);

        if ($startLong !== false && $endLong !== false && $startLong > $endLong) {
            $this->addError($endErrorField, 'Range end harus >= range start.');

            return false;
        }

        foreach (['gatewayIp' => $gatewayIp, 'rangeStart' => $rangeStart, 'rangeEnd' => $rangeEnd] as $field => $ip) {
            if (! CustomerIpPoolService::ipWithinCidr($ip, $cidr)) {
                $this->addError($endErrorField === 'editRangeEnd' ? 'edit'.ucfirst($field) : $field, 'Harus berada di dalam network address '.$cidr.'.');

                return false;
            }
        }

        if ($service->overlapsExistingRange((int) $nasId, $rangeStart, $rangeEnd, $ignorePoolId)) {
            $this->addError($endErrorField, 'Range ini tumpang tindih dengan pool lain yang sudah ada di NAS ini.');

            return false;
        }

        return true;
    }

    public function deletePool(int $poolId, CustomerIpPoolService $service): void
    {
        $this->authorize('manage', CustomerIpPool::class);

        $service->delete(CustomerIpPool::findOrFail($poolId));
    }

    /**
     * v0.14.2.1 — "Sync Ulang" button, shown only for a pool whose last
     * RouterOS live-push attempt is Gagal (enforced here too, not just by
     * the button's own @if in the Blade view — defense in depth, same
     * posture as every other authorize() check in this codebase).
     */
    public function resyncPool(int $poolId, CustomerIpPoolService $service): void
    {
        $this->authorize('manage', CustomerIpPool::class);

        $service->resync(CustomerIpPool::findOrFail($poolId));
    }

    public function render()
    {
        $pools = CustomerIpPool::query()
            ->with('nas:id,name')
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->when($this->filterNasId, fn ($query) => $query->where('nas_id', $this->filterNasId))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(15);

        return view('livewire.network.customer-ip-pool-index', [
            'pools' => $pools,
            // v0.14.2.2 — drives conditional wire:poll in the Blade view:
            // only the CURRENTLY DISPLAYED page's rows matter (a Pending
            // row on some other page shouldn't keep this page polling
            // forever), and only while at least one is still Pending —
            // once every visible row is Synced/Gagal, the next render
            // simply omits the wire:poll attribute and Livewire's own
            // poll mechanism stops firing, no manual interval teardown
            // needed (Livewire's documented conditional-polling pattern).
            'hasPendingSync' => $pools->contains(fn (CustomerIpPool $pool) => $pool->mikrotik_sync_status === MikrotikSyncStatus::Pending),
            'nasOptions' => Nas::query()->orderBy('name')->get(['id', 'name']),
            'canManage' => auth()->user()->can('manage', CustomerIpPool::class),
        ]);
    }

    /**
     * Revisi Pesan Error Bahasa Indonesia — nama field di pesan validasi
     * (mis. "Harga Jual wajib diisi." bukan "The sell price field is
     * required.") lewat satu sumber tunggal dipakai lintas seluruh cluster
     * "Profil Paket" — lihat ProfilPaketAttributeLabels sendiri. Mencakup
     * juga varian `edit`-prefixed (mis. editSellPrice) secara otomatis.
     */
    public function validationAttributes(): array
    {
        return ProfilPaketAttributeLabels::forLivewire();
    }
}
