<?php

namespace App\Livewire\Network;

use App\Enums\MikrotikSyncStatus;
use App\Models\BandwidthProfile;
use App\Models\HotspotPackage;
use App\Models\NetworkProfileGroup;
use App\Services\Network\HotspotPackageService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * v0.14.4 — cluster "Profil Paket". A sellable Hotspot voucher/token
 * package catalog entry, referencing a Grup Profil (v0.14.3, type=hotspot
 * only) and a Bandwidth Profile (v0.14.1). See HotspotPackage's own
 * docblock for the full design reasoning.
 */
class HotspotPackageIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    private const DAY_OPTIONS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    private const DAY_LABELS = [
        'monday' => 'Senin',
        'tuesday' => 'Selasa',
        'wednesday' => 'Rabu',
        'thursday' => 'Kamis',
        'friday' => 'Jumat',
        'saturday' => 'Sabtu',
        'sunday' => 'Minggu',
    ];

    public string $search = '';

    public string $filterGroupId = '';

    public string $sortBy = 'name';

    public string $sortDir = 'asc';

    public bool $showCreateForm = false;

    #[Validate('required|integer')]
    public string $networkProfileGroupId = '';

    #[Validate('required|integer')]
    public string $bandwidthProfileId = '';

    #[Validate('required|string|max:255')]
    public string $name = '';

    public bool $visibleToReseller = false;

    public bool $showInVoucherForm = true;

    public string $costPrice = '0';

    public string $sellPrice = '0';

    public string $promoPrice = '';

    public string $taxPercent = '0';

    public string $profileType = 'unlimited';

    public string $limitType = '';

    public string $activeDurationValue = '';

    public string $activeDurationUnit = 'day';

    public string $sharedUsers = '1';

    public string $priority = 'Default';

    /** @var array<int, string> */
    public array $loginDays = [];

    public string $loginStartTime = '';

    public string $loginEndTime = '';

    public bool $isActive = true;

    public ?int $editingPackageId = null;

    public string $editNetworkProfileGroupId = '';

    public string $editBandwidthProfileId = '';

    public string $editName = '';

    public bool $editVisibleToReseller = false;

    public bool $editShowInVoucherForm = true;

    public string $editCostPrice = '0';

    public string $editSellPrice = '0';

    public string $editPromoPrice = '';

    public string $editTaxPercent = '0';

    public string $editProfileType = 'unlimited';

    public string $editLimitType = '';

    public string $editActiveDurationValue = '';

    public string $editActiveDurationUnit = 'day';

    public string $editSharedUsers = '1';

    public string $editPriority = 'Default';

    /** @var array<int, string> */
    public array $editLoginDays = [];

    public string $editLoginStartTime = '';

    public string $editLoginEndTime = '';

    public bool $editIsActive = true;

    public function mount(): void
    {
        $this->authorize('viewAny', HotspotPackage::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterGroupId(): void
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
    private function baseRules(string $groupField, string $nameField, ?int $ignoreId): array
    {
        return [
            $groupField => ['required', 'integer'],
            $nameField => [
                'required', 'string', 'max:255',
                Rule::unique(HotspotPackage::class, 'name')
                    ->where('network_profile_group_id', $this->{$groupField})
                    ->whereNull('deleted_at')
                    ->when($ignoreId, fn ($rule) => $rule->ignore($ignoreId)),
            ],
        ];
    }

    /**
     * Mirrors StoreHotspotPackageRequest/UpdateHotspotPackageRequest's own
     * validateGroupIsHotspotType() — Livewire's inline validate() array
     * can't express a validator-level "after" hook the same way.
     */
    private function validateGroupIsHotspotType(string $groupId, string $errorField): bool
    {
        $group = NetworkProfileGroup::find((int) $groupId);

        if ($group === null) {
            $this->addError($errorField, 'Grup Profil yang dipilih tidak ditemukan.');

            return false;
        }

        if ($group->type->value !== 'hotspot') {
            $this->addError($errorField, 'Grup Profil yang dipilih harus bertipe Hotspot.');

            return false;
        }

        return true;
    }

    /**
     * @return array{profile_type: string, limit_type: ?string, active_duration_value: ?int, active_duration_unit: ?string}
     */
    private function durationData(string $profileType, string $limitType, string $durationValue, string $durationUnit): array
    {
        if ($profileType !== 'limited') {
            return ['profile_type' => $profileType, 'limit_type' => null, 'active_duration_value' => null, 'active_duration_unit' => null];
        }

        return [
            'profile_type' => $profileType,
            'limit_type' => $limitType,
            'active_duration_value' => $durationValue !== '' ? (int) $durationValue : null,
            'active_duration_unit' => $durationUnit,
        ];
    }

    public function createPackage(HotspotPackageService $service): void
    {
        $this->authorize('manage', HotspotPackage::class);

        $this->name = trim($this->name);

        $this->validate(array_merge($this->baseRules('networkProfileGroupId', 'name', null), [
            'bandwidthProfileId' => ['required', 'integer'],
            'costPrice' => ['required', 'numeric', 'min:0'],
            'sellPrice' => ['required', 'numeric', 'min:0', 'gte:costPrice'],
            'promoPrice' => ['nullable', 'numeric', 'min:0'],
            'taxPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'profileType' => ['required', 'string', 'in:unlimited,limited'],
            'limitType' => ['required_if:profileType,limited', 'nullable', 'string', 'in:time_base,quota_base'],
            'activeDurationValue' => ['required_if:profileType,limited', 'nullable', 'integer', 'min:1'],
            'activeDurationUnit' => ['required_if:profileType,limited', 'nullable', 'string', 'in:minute,hour,day,month'],
            'sharedUsers' => ['required', 'integer', 'min:1'],
            'priority' => ['nullable', 'string', 'max:50'],
            'loginDays' => ['nullable', 'array'],
            'loginDays.*' => ['string', 'in:'.implode(',', self::DAY_OPTIONS)],
            'loginStartTime' => ['nullable', 'date_format:H:i'],
            'loginEndTime' => ['nullable', 'date_format:H:i', 'after:loginStartTime'],
        ]));

        if (! $this->validateGroupIsHotspotType($this->networkProfileGroupId, 'networkProfileGroupId')) {
            return;
        }

        $service->create(array_merge([
            'network_profile_group_id' => (int) $this->networkProfileGroupId,
            'bandwidth_profile_id' => (int) $this->bandwidthProfileId,
            'name' => $this->name,
            'visible_to_reseller' => $this->visibleToReseller,
            'show_in_voucher_form' => $this->showInVoucherForm,
            'cost_price' => $this->costPrice,
            'sell_price' => $this->sellPrice,
            'promo_price' => $this->promoPrice !== '' ? $this->promoPrice : null,
            'tax_percent' => $this->taxPercent,
            'shared_users' => (int) $this->sharedUsers,
            'priority' => $this->priority ?: 'Default',
            'login_days' => $this->loginDays === [] ? null : $this->loginDays,
            'login_start_time' => $this->loginStartTime ?: null,
            'login_end_time' => $this->loginEndTime ?: null,
            'is_active' => $this->isActive,
        ], $this->durationData($this->profileType, $this->limitType, $this->activeDurationValue, $this->activeDurationUnit)));

        $this->reset([
            'networkProfileGroupId', 'bandwidthProfileId', 'name', 'visibleToReseller', 'showInVoucherForm',
            'costPrice', 'sellPrice', 'promoPrice', 'taxPercent', 'profileType', 'limitType',
            'activeDurationValue', 'activeDurationUnit', 'sharedUsers', 'priority', 'loginDays',
            'loginStartTime', 'loginEndTime', 'isActive', 'showCreateForm',
        ]);
        $this->costPrice = '0';
        $this->sellPrice = '0';
        $this->taxPercent = '0';
        $this->profileType = 'unlimited';
        $this->activeDurationUnit = 'day';
        $this->sharedUsers = '1';
        $this->priority = 'Default';
        $this->showInVoucherForm = true;
        $this->isActive = true;
    }

    public function edit(int $packageId): void
    {
        $package = HotspotPackage::findOrFail($packageId);
        $this->authorize('manage', HotspotPackage::class);

        $this->editingPackageId = $package->id;
        $this->editNetworkProfileGroupId = (string) $package->network_profile_group_id;
        $this->editBandwidthProfileId = (string) $package->bandwidth_profile_id;
        $this->editName = $package->name;
        $this->editVisibleToReseller = $package->visible_to_reseller;
        $this->editShowInVoucherForm = $package->show_in_voucher_form;
        $this->editCostPrice = (string) $package->cost_price;
        $this->editSellPrice = (string) $package->sell_price;
        $this->editPromoPrice = $package->promo_price !== null ? (string) $package->promo_price : '';
        $this->editTaxPercent = (string) $package->tax_percent;
        $this->editProfileType = $package->profile_type->value;
        $this->editLimitType = $package->limit_type?->value ?? '';
        $this->editActiveDurationValue = $package->active_duration_value !== null ? (string) $package->active_duration_value : '';
        $this->editActiveDurationUnit = $package->active_duration_unit?->value ?? 'day';
        $this->editSharedUsers = (string) $package->shared_users;
        $this->editPriority = $package->priority;
        $this->editLoginDays = $package->login_days ?? [];
        $this->editLoginStartTime = $package->login_start_time !== null ? substr($package->login_start_time, 0, 5) : '';
        $this->editLoginEndTime = $package->login_end_time !== null ? substr($package->login_end_time, 0, 5) : '';
        $this->editIsActive = $package->is_active;
    }

    public function cancelEdit(): void
    {
        $this->reset([
            'editingPackageId', 'editNetworkProfileGroupId', 'editBandwidthProfileId', 'editName',
            'editVisibleToReseller', 'editShowInVoucherForm', 'editCostPrice', 'editSellPrice',
            'editPromoPrice', 'editTaxPercent', 'editProfileType', 'editLimitType', 'editActiveDurationValue',
            'editActiveDurationUnit', 'editSharedUsers', 'editPriority', 'editLoginDays', 'editLoginStartTime',
            'editLoginEndTime', 'editIsActive',
        ]);
        $this->editProfileType = 'unlimited';
        $this->editActiveDurationUnit = 'day';
        $this->editSharedUsers = '1';
        $this->editPriority = 'Default';
    }

    public function updatePackage(HotspotPackageService $service): void
    {
        $package = HotspotPackage::findOrFail($this->editingPackageId);
        $this->authorize('manage', HotspotPackage::class);

        $this->editName = trim($this->editName);

        $this->validate(array_merge($this->baseRules('editNetworkProfileGroupId', 'editName', $package->id), [
            'editBandwidthProfileId' => ['required', 'integer'],
            'editCostPrice' => ['required', 'numeric', 'min:0'],
            'editSellPrice' => ['required', 'numeric', 'min:0', 'gte:editCostPrice'],
            'editPromoPrice' => ['nullable', 'numeric', 'min:0'],
            'editTaxPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'editProfileType' => ['required', 'string', 'in:unlimited,limited'],
            'editLimitType' => ['required_if:editProfileType,limited', 'nullable', 'string', 'in:time_base,quota_base'],
            'editActiveDurationValue' => ['required_if:editProfileType,limited', 'nullable', 'integer', 'min:1'],
            'editActiveDurationUnit' => ['required_if:editProfileType,limited', 'nullable', 'string', 'in:minute,hour,day,month'],
            'editSharedUsers' => ['required', 'integer', 'min:1'],
            'editPriority' => ['nullable', 'string', 'max:50'],
            'editLoginDays' => ['nullable', 'array'],
            'editLoginDays.*' => ['string', 'in:'.implode(',', self::DAY_OPTIONS)],
            'editLoginStartTime' => ['nullable', 'date_format:H:i'],
            'editLoginEndTime' => ['nullable', 'date_format:H:i', 'after:editLoginStartTime'],
        ]));

        if (! $this->validateGroupIsHotspotType($this->editNetworkProfileGroupId, 'editNetworkProfileGroupId')) {
            return;
        }

        $service->update($package, array_merge([
            'network_profile_group_id' => (int) $this->editNetworkProfileGroupId,
            'bandwidth_profile_id' => (int) $this->editBandwidthProfileId,
            'name' => $this->editName,
            'visible_to_reseller' => $this->editVisibleToReseller,
            'show_in_voucher_form' => $this->editShowInVoucherForm,
            'cost_price' => $this->editCostPrice,
            'sell_price' => $this->editSellPrice,
            'promo_price' => $this->editPromoPrice !== '' ? $this->editPromoPrice : null,
            'tax_percent' => $this->editTaxPercent,
            'shared_users' => (int) $this->editSharedUsers,
            'priority' => $this->editPriority ?: 'Default',
            'login_days' => $this->editLoginDays === [] ? null : $this->editLoginDays,
            'login_start_time' => $this->editLoginStartTime ?: null,
            'login_end_time' => $this->editLoginEndTime ?: null,
            'is_active' => $this->editIsActive,
        ], $this->durationData($this->editProfileType, $this->editLimitType, $this->editActiveDurationValue, $this->editActiveDurationUnit)));

        $this->cancelEdit();
    }

    public function deletePackage(int $packageId, HotspotPackageService $service): void
    {
        $this->authorize('manage', HotspotPackage::class);

        $service->delete(HotspotPackage::findOrFail($packageId));
    }

    public function resyncPackage(int $packageId, HotspotPackageService $service): void
    {
        $this->authorize('manage', HotspotPackage::class);

        $service->resync(HotspotPackage::findOrFail($packageId));
    }

    public function render()
    {
        $packages = HotspotPackage::query()
            ->with(['networkProfileGroup:id,name,nas_id', 'networkProfileGroup.nas:id,name', 'bandwidthProfile:id,name'])
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->when($this->filterGroupId, fn ($query) => $query->where('network_profile_group_id', $this->filterGroupId))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(15);

        return view('livewire.network.hotspot-package-index', [
            'packages' => $packages,
            // Same conditional wire:poll pattern as CustomerIpPoolIndex/
            // NetworkProfileGroupIndex (v0.14.2.2) — reused, not reinvented.
            'hasPendingSync' => $packages->contains(fn (HotspotPackage $package) => $package->mikrotik_sync_status === MikrotikSyncStatus::Pending),
            'groupOptions' => NetworkProfileGroup::query()->where('type', 'hotspot')->orderBy('name')->get(['id', 'name']),
            'bandwidthProfileOptions' => BandwidthProfile::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'dayOptions' => self::DAY_OPTIONS,
            'dayLabels' => self::DAY_LABELS,
            'canManage' => auth()->user()->can('manage', HotspotPackage::class),
        ]);
    }
}
