<?php

namespace App\Livewire\Network;

use App\Enums\MikrotikSyncStatus;
use App\Models\BandwidthProfile;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Services\Network\PppPackageService;
use App\Support\ProfilPaketAttributeLabels;
use App\Support\RouterOsQueuePriority;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * v0.14.5 — cluster "Profil Paket". A sellable monthly PPPoE subscription
 * package catalog entry, referencing a Grup Profil (v0.14.3, type=ppp only)
 * and a Bandwidth Profile (v0.14.1). See PppPackage's own docblock for the
 * full design reasoning.
 */
class PppPackageIndex extends Component
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

    public string $costPrice = '0';

    public string $sellPrice = '0';

    public string $promoPrice = '';

    public string $taxPercent = '0';

    public string $activeDurationValue = '1';

    public string $activeDurationUnit = 'month';

    public string $sharedUsers = '1';

    // Revisi Prioritas Dropdown — dulu text bebas ('Default'), sekarang
    // dropdown RouterOS Queue Priority 1-8, default 8 (default RouterOS
    // sendiri, lihat App\Support\RouterOsQueuePriority).
    public string $priority = '8';

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

    public string $editCostPrice = '0';

    public string $editSellPrice = '0';

    public string $editPromoPrice = '';

    public string $editTaxPercent = '0';

    public string $editActiveDurationValue = '1';

    public string $editActiveDurationUnit = 'month';

    public string $editSharedUsers = '1';

    public string $editPriority = '8';

    /** @var array<int, string> */
    public array $editLoginDays = [];

    public string $editLoginStartTime = '';

    public string $editLoginEndTime = '';

    public bool $editIsActive = true;

    public function mount(): void
    {
        $this->authorize('viewAny', PppPackage::class);
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
                Rule::unique(PppPackage::class, 'name')
                    ->where('network_profile_group_id', $this->{$groupField})
                    ->whereNull('deleted_at')
                    ->when($ignoreId, fn ($rule) => $rule->ignore($ignoreId)),
            ],
        ];
    }

    /**
     * Mirrors StorePppPackageRequest/UpdatePppPackageRequest's own
     * validateGroupIsPppType() — Livewire's inline validate() array can't
     * express a validator-level "after" hook the same way.
     */
    private function validateGroupIsPppType(string $groupId, string $errorField): ?NetworkProfileGroup
    {
        $group = NetworkProfileGroup::find((int) $groupId);

        if ($group === null) {
            $this->addError($errorField, 'Grup Profil yang dipilih tidak ditemukan.');

            return null;
        }

        if ($group->type->value !== 'ppp') {
            $this->addError($errorField, 'Grup Profil yang dipilih harus bertipe PPP.');

            return null;
        }

        return $group;
    }

    /**
     * Mirrors StorePppPackageRequest/UpdatePppPackageRequest's own
     * validateNoNameCollisionOnNas() — aturan nama final (2026-09-05):
     * hanya blokir bentrok dengan dunia HOTSPOT (Grup Profil tipe hotspot /
     * Profil Hotspot) di NAS yang sama; dunia PPP bebas senama. Lihat
     * PppPackage::collidesWithExistingName()'s own docblock.
     */
    private function validateNoNameCollisionOnNas(NetworkProfileGroup $group, string $name, string $errorField): bool
    {
        if (PppPackage::collidesWithExistingName($group->nas_id, $name)) {
            $this->addError($errorField, 'Nama ini sudah dipakai Grup Profil Hotspot atau Profil Hotspot di NAS yang sama — nama Paket/Profil PPP tidak boleh bentrok dengan dunia Hotspot.');

            return false;
        }

        return true;
    }

    public function createPackage(PppPackageService $service): void
    {
        $this->authorize('manage', PppPackage::class);

        $this->name = trim($this->name);

        $this->validate(array_merge($this->baseRules('networkProfileGroupId', 'name', null), [
            'bandwidthProfileId' => ['required', 'integer'],
            'costPrice' => ['required', 'numeric', 'min:0'],
            'sellPrice' => ['required', 'numeric', 'min:0', 'gte:costPrice'],
            'promoPrice' => ['nullable', 'numeric', 'min:0'],
            'taxPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'activeDurationValue' => ['required', 'integer', 'min:1'],
            'activeDurationUnit' => ['required', 'string', 'in:minute,hour,day,month'],
            'sharedUsers' => ['required', 'integer', 'min:1'],
            'priority' => ['nullable', 'integer', 'between:1,8'],
            'loginDays' => ['nullable', 'array'],
            'loginDays.*' => ['string', 'in:'.implode(',', self::DAY_OPTIONS)],
            'loginStartTime' => ['nullable', 'date_format:H:i'],
            'loginEndTime' => ['nullable', 'date_format:H:i', 'after:loginStartTime'],
        ]));

        $group = $this->validateGroupIsPppType($this->networkProfileGroupId, 'networkProfileGroupId');

        if ($group === null) {
            return;
        }

        if (! $this->validateNoNameCollisionOnNas($group, $this->name, 'name')) {
            return;
        }

        $service->create([
            'network_profile_group_id' => (int) $this->networkProfileGroupId,
            'bandwidth_profile_id' => (int) $this->bandwidthProfileId,
            'name' => $this->name,
            'visible_to_reseller' => $this->visibleToReseller,
            'cost_price' => $this->costPrice,
            'sell_price' => $this->sellPrice,
            'promo_price' => $this->promoPrice !== '' ? $this->promoPrice : null,
            'tax_percent' => $this->taxPercent,
            'active_duration_value' => (int) $this->activeDurationValue,
            'active_duration_unit' => $this->activeDurationUnit,
            'shared_users' => (int) $this->sharedUsers,
            'priority' => (int) $this->priority,
            'login_days' => $this->loginDays === [] ? null : $this->loginDays,
            'login_start_time' => $this->loginStartTime ?: null,
            'login_end_time' => $this->loginEndTime ?: null,
            'is_active' => $this->isActive,
        ]);

        $this->reset([
            'networkProfileGroupId', 'bandwidthProfileId', 'name', 'visibleToReseller',
            'costPrice', 'sellPrice', 'promoPrice', 'taxPercent',
            'activeDurationValue', 'activeDurationUnit', 'sharedUsers', 'priority', 'loginDays',
            'loginStartTime', 'loginEndTime', 'isActive', 'showCreateForm',
        ]);
        $this->costPrice = '0';
        $this->sellPrice = '0';
        $this->taxPercent = '0';
        $this->activeDurationValue = '1';
        $this->activeDurationUnit = 'month';
        $this->sharedUsers = '1';
        $this->priority = '8';
        $this->isActive = true;
    }

    public function edit(int $packageId): void
    {
        $package = PppPackage::findOrFail($packageId);
        $this->authorize('manage', PppPackage::class);

        $this->editingPackageId = $package->id;
        $this->editNetworkProfileGroupId = (string) $package->network_profile_group_id;
        $this->editBandwidthProfileId = (string) $package->bandwidth_profile_id;
        $this->editName = $package->name;
        $this->editVisibleToReseller = $package->visible_to_reseller;
        $this->editCostPrice = (string) $package->cost_price;
        $this->editSellPrice = (string) $package->sell_price;
        $this->editPromoPrice = $package->promo_price !== null ? (string) $package->promo_price : '';
        $this->editTaxPercent = (string) $package->tax_percent;
        $this->editActiveDurationValue = (string) $package->active_duration_value;
        $this->editActiveDurationUnit = $package->active_duration_unit->value;
        $this->editSharedUsers = (string) $package->shared_users;
        $this->editPriority = (string) $package->priority;
        $this->editLoginDays = $package->login_days ?? [];
        $this->editLoginStartTime = $package->login_start_time !== null ? substr($package->login_start_time, 0, 5) : '';
        $this->editLoginEndTime = $package->login_end_time !== null ? substr($package->login_end_time, 0, 5) : '';
        $this->editIsActive = $package->is_active;
    }

    public function cancelEdit(): void
    {
        $this->reset([
            'editingPackageId', 'editNetworkProfileGroupId', 'editBandwidthProfileId', 'editName',
            'editVisibleToReseller', 'editCostPrice', 'editSellPrice',
            'editPromoPrice', 'editTaxPercent', 'editActiveDurationValue',
            'editActiveDurationUnit', 'editSharedUsers', 'editPriority',
            'editLoginDays', 'editLoginStartTime', 'editLoginEndTime', 'editIsActive',
        ]);
        $this->editActiveDurationValue = '1';
        $this->editActiveDurationUnit = 'month';
        $this->editSharedUsers = '1';
        $this->editPriority = '8';
    }

    public function updatePackage(PppPackageService $service): void
    {
        $package = PppPackage::findOrFail($this->editingPackageId);
        $this->authorize('manage', PppPackage::class);

        $this->editName = trim($this->editName);

        $this->validate(array_merge($this->baseRules('editNetworkProfileGroupId', 'editName', $package->id), [
            'editBandwidthProfileId' => ['required', 'integer'],
            'editCostPrice' => ['required', 'numeric', 'min:0'],
            'editSellPrice' => ['required', 'numeric', 'min:0', 'gte:editCostPrice'],
            'editPromoPrice' => ['nullable', 'numeric', 'min:0'],
            'editTaxPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'editActiveDurationValue' => ['required', 'integer', 'min:1'],
            'editActiveDurationUnit' => ['required', 'string', 'in:minute,hour,day,month'],
            'editSharedUsers' => ['required', 'integer', 'min:1'],
            'editPriority' => ['nullable', 'integer', 'between:1,8'],
            'editLoginDays' => ['nullable', 'array'],
            'editLoginDays.*' => ['string', 'in:'.implode(',', self::DAY_OPTIONS)],
            'editLoginStartTime' => ['nullable', 'date_format:H:i'],
            'editLoginEndTime' => ['nullable', 'date_format:H:i', 'after:editLoginStartTime'],
        ]));

        $group = $this->validateGroupIsPppType($this->editNetworkProfileGroupId, 'editNetworkProfileGroupId');

        if ($group === null) {
            return;
        }

        if (! $this->validateNoNameCollisionOnNas($group, $this->editName, 'editName')) {
            return;
        }

        $service->update($package, [
            'network_profile_group_id' => (int) $this->editNetworkProfileGroupId,
            'bandwidth_profile_id' => (int) $this->editBandwidthProfileId,
            'name' => $this->editName,
            'visible_to_reseller' => $this->editVisibleToReseller,
            'cost_price' => $this->editCostPrice,
            'sell_price' => $this->editSellPrice,
            'promo_price' => $this->editPromoPrice !== '' ? $this->editPromoPrice : null,
            'tax_percent' => $this->editTaxPercent,
            'active_duration_value' => (int) $this->editActiveDurationValue,
            'active_duration_unit' => $this->editActiveDurationUnit,
            'shared_users' => (int) $this->editSharedUsers,
            'priority' => (int) $this->editPriority,
            'login_days' => $this->editLoginDays === [] ? null : $this->editLoginDays,
            'login_start_time' => $this->editLoginStartTime ?: null,
            'login_end_time' => $this->editLoginEndTime ?: null,
            'is_active' => $this->editIsActive,
        ]);

        $this->cancelEdit();
    }

    public function deletePackage(int $packageId, PppPackageService $service): void
    {
        $this->authorize('manage', PppPackage::class);

        $service->delete(PppPackage::findOrFail($packageId));
    }

    public function resyncPackage(int $packageId, PppPackageService $service): void
    {
        $this->authorize('manage', PppPackage::class);

        $service->resync(PppPackage::findOrFail($packageId));
    }

    public function render()
    {
        $packages = PppPackage::query()
            ->with(['networkProfileGroup:id,name,nas_id', 'networkProfileGroup.nas:id,name', 'bandwidthProfile:id,name'])
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->when($this->filterGroupId, fn ($query) => $query->where('network_profile_group_id', $this->filterGroupId))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(15);

        return view('livewire.network.ppp-package-index', [
            'packages' => $packages,
            // Same conditional wire:poll pattern as HotspotPackageIndex/
            // CustomerIpPoolIndex/NetworkProfileGroupIndex — reused, not
            // reinvented.
            'hasPendingSync' => $packages->contains(fn (PppPackage $package) => $package->mikrotik_sync_status === MikrotikSyncStatus::Pending),
            'groupOptions' => NetworkProfileGroup::query()->where('type', 'ppp')->orderBy('name')->get(['id', 'name']),
            'bandwidthProfileOptions' => BandwidthProfile::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'dayOptions' => self::DAY_OPTIONS,
            'dayLabels' => self::DAY_LABELS,
            'priorityOptions' => RouterOsQueuePriority::options(),
            'canManage' => auth()->user()->can('manage', PppPackage::class),
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
