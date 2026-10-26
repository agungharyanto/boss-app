<?php

namespace App\Livewire\Network;

use App\Enums\SsidBand;
use App\Enums\WifiRole;
use App\Models\Customer;
use App\Models\PppPackage;
use App\Models\ProvisioningProfile;
use App\Services\Network\Provisioning\ProvisioningProfileService;
use App\Services\Network\Provisioning\SsidNameResolver;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * v0.23.8.6b — CRUD "Profil Provisioning" (/provisioning-profiles). Pola sama
 * cluster "Profil Paket" (PppPackageIndex): tenant scope via BelongsToTenant,
 * permission view/manage, tulis lewat ProvisioningProfileService (BOSS-006).
 * NOL push ke device, NOL tombol "Provision" — murni data.
 */
class ProvisioningProfileIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    // --- Profil dasar ---
    public string $name = '';

    public string $slug = '';

    public string $description = '';

    public bool $enabled = false;

    // --- WAN Internet (wajib) ---
    public string $wanMode = 'pppoe';

    public string $wanVlan = '';

    public bool $wanNatEnabled = true;

    public string $wanServiceType = 'INTERNET';

    public string $wanWifiRole = 'primary_wifi';

    public string $pppoeUsernameStrategy = 'derive_from_cid';

    public string $pppoeUsernameStatic = '';

    public string $pppoePasswordStrategy = 'constant';

    public string $pppoePasswordStatic = '';

    public string $staticIp = '';

    public string $staticGateway = '';

    public string $staticNetmask = '';

    public string $staticDns = '';

    // --- WAN Bridge (opsional) ---
    public bool $hasBridge = false;

    public string $bridgeVlan = '';

    public string $bridgeServiceType = 'INTERNET';

    public string $bridgeWifiRole = 'guest_wifi';

    // --- SSID (repeatable) ---
    /** @var array<int, array{wifi_role:string,band:string,ssid_name:string,security_mode:string,password:string}> */
    public array $ssids = [];

    // --- Tautan paket ---
    /** @var array<int, int> */
    public array $linkedPackageIds = [];

    /** @var array<int, int> */
    public array $defaultPackageIds = [];

    public function mount(): void
    {
        $this->authorize('viewAny', ProvisioningProfile::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->authorize('manage', ProvisioningProfile::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function addSsid(): void
    {
        $this->ssids[] = ['wifi_role' => 'primary_wifi', 'band' => '2.4g', 'ssid_name' => '', 'security_mode' => 'open', 'password' => ''];
    }

    public function removeSsid(int $index): void
    {
        unset($this->ssids[$index]);
        $this->ssids = array_values($this->ssids);
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'slug', 'description', 'enabled',
            'wanMode', 'wanVlan', 'wanNatEnabled', 'wanServiceType', 'wanWifiRole',
            'pppoeUsernameStrategy', 'pppoeUsernameStatic', 'pppoePasswordStrategy', 'pppoePasswordStatic',
            'staticIp', 'staticGateway', 'staticNetmask', 'staticDns',
            'hasBridge', 'bridgeVlan', 'bridgeServiceType', 'bridgeWifiRole',
            'ssids', 'linkedPackageIds', 'defaultPackageIds',
        ]);
        $this->wanMode = 'pppoe';
        $this->wanNatEnabled = true;
        $this->wanServiceType = 'INTERNET';
        $this->wanWifiRole = 'primary_wifi';
        $this->pppoeUsernameStrategy = 'derive_from_cid';
        $this->pppoePasswordStrategy = 'constant';
        $this->bridgeServiceType = 'INTERNET';
        $this->bridgeWifiRole = 'guest_wifi';
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $this->authorize('manage', ProvisioningProfile::class);
        $profile = ProvisioningProfile::with(['wanInternet', 'wanBridge', 'ssids', 'pppPackages'])->findOrFail($id);
        $this->resetForm();

        $this->editingId = $profile->id;
        $this->name = $profile->name;
        $this->slug = $profile->slug;
        $this->description = (string) $profile->description;
        $this->enabled = $profile->enabled;

        if ($wi = $profile->wanInternet) {
            $this->wanMode = $wi->wan_mode->value;
            $this->wanVlan = (string) $wi->vlan;
            $this->wanNatEnabled = $wi->nat_enabled;
            $this->wanServiceType = (string) $wi->service_type;
            $this->wanWifiRole = $wi->wifi_role->value;
            $this->pppoeUsernameStrategy = (string) ($wi->pppoe_username_strategy ?? 'derive_from_cid');
            $this->pppoeUsernameStatic = (string) $wi->pppoe_username_static;
            $this->pppoePasswordStrategy = (string) ($wi->pppoe_password_strategy ?? 'constant');
            // password static TIDAK pernah di-prefill (blank = tak diubah).
            $this->staticIp = (string) $wi->static_ip;
            $this->staticGateway = (string) $wi->static_gateway;
            $this->staticNetmask = (string) $wi->static_netmask;
            $this->staticDns = (string) $wi->static_dns;
        }

        if ($wb = $profile->wanBridge) {
            $this->hasBridge = true;
            $this->bridgeVlan = (string) $wb->vlan;
            $this->bridgeServiceType = (string) $wb->service_type;
            $this->bridgeWifiRole = $wb->wifi_role->value;
        }

        $this->ssids = $profile->ssids->map(fn ($s) => [
            'wifi_role' => $s->wifi_role->value,
            'band' => $s->band->value,
            'ssid_name' => $s->ssid_name,
            'security_mode' => $s->security_mode->value,
            'password' => '',  // tak pernah di-prefill
        ])->values()->all();

        $this->linkedPackageIds = $profile->pppPackages->pluck('id')->all();
        $this->defaultPackageIds = $profile->pppPackages->filter(fn ($p) => (bool) $p->pivot->is_default)->pluck('id')->all();

        $this->showForm = true;
    }

    private function formRules(): array
    {
        $bands = implode(',', array_map(fn ($b) => $b->value, SsidBand::cases()));
        $roles = implode(',', array_map(fn ($r) => $r->value, WifiRole::cases()));

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/',
                Rule::unique(ProvisioningProfile::class, 'slug')->when($this->editingId, fn ($r) => $r->ignore($this->editingId)),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'wanMode' => ['required', 'in:pppoe,dhcp,static'],
            'wanVlan' => ['required', 'integer', 'between:1,4094'],
            'wanServiceType' => ['required', 'string', 'max:32'],
            'wanWifiRole' => ['required', 'in:primary_wifi,guest_wifi,none'],
            'pppoeUsernameStrategy' => ['required_if:wanMode,pppoe', 'nullable', 'in:derive_from_cid,static'],
            'pppoeUsernameStatic' => ['nullable', 'required_if:pppoeUsernameStrategy,static', 'string', 'max:255'],
            'pppoePasswordStrategy' => ['required_if:wanMode,pppoe', 'nullable', 'in:constant,static'],
            'staticIp' => ['nullable', 'required_if:wanMode,static', 'ip'],
            'staticGateway' => ['nullable', 'required_if:wanMode,static', 'ip'],
            'staticNetmask' => ['nullable', 'required_if:wanMode,static', 'string', 'max:64'],
            'staticDns' => ['nullable', 'string', 'max:255'],
            'bridgeVlan' => ['nullable', 'required_if:hasBridge,true', 'integer', 'between:1,4094'],
            'bridgeServiceType' => ['nullable', 'required_if:hasBridge,true', 'string', 'max:32'],
            'bridgeWifiRole' => ['nullable', 'required_if:hasBridge,true', 'in:primary_wifi,guest_wifi,none'],
            'ssids' => ['array'],
            'ssids.*.wifi_role' => ['required', "in:{$roles}"],
            'ssids.*.band' => ['required', "in:{$bands}"],
            'ssids.*.ssid_name' => ['required', 'string', 'max:255'],
            'ssids.*.security_mode' => ['required', 'in:open,wpa2'],
            'ssids.*.password' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Validasi silang yang tak bisa diungkap array-rules: VLAN internet != bridge,
     * SSID unik per (peran,band), wpa2 BARU wajib password (edit boleh kosong =
     * tak diubah kalau sudah ada password tersimpan).
     */
    private function validateCross(): bool
    {
        $ok = true;

        if ($this->hasBridge && $this->bridgeVlan !== '' && $this->wanVlan !== '' && (int) $this->bridgeVlan === (int) $this->wanVlan) {
            $this->addError('bridgeVlan', 'VLAN bridge tidak boleh sama dengan VLAN WAN Internet.');
            $ok = false;
        }

        $seen = [];
        foreach ($this->ssids as $i => $row) {
            $key = ($row['wifi_role'] ?? '').'|'.($row['band'] ?? '');
            if (isset($seen[$key])) {
                $this->addError("ssids.{$i}.band", 'Kombinasi peran WiFi + band ini duplikat.');
                $ok = false;
            }
            $seen[$key] = true;

            if (($row['security_mode'] ?? '') === 'wpa2' && ($row['password'] ?? '') === '') {
                $hasStored = $this->editingId !== null && ProvisioningProfile::find($this->editingId)?->ssids()
                    ->where('wifi_role', $row['wifi_role'])->where('band', $row['band'])
                    ->whereNotNull('password')->exists();
                if (! $hasStored) {
                    $this->addError("ssids.{$i}.password", 'SSID wpa2 wajib punya password.');
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    public function save(ProvisioningProfileService $service): void
    {
        $this->authorize('manage', ProvisioningProfile::class);
        $this->name = trim($this->name);
        if ($this->slug === '') {
            $this->slug = Str::slug($this->name);
        }

        $this->validate($this->formRules());
        if (! $this->validateCross()) {
            return;
        }

        $profileData = [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description !== '' ? $this->description : null,
            'enabled' => $this->enabled,
        ];

        $wanInternet = [
            'wan_mode' => $this->wanMode,
            'vlan' => (int) $this->wanVlan,
            'nat_enabled' => $this->wanNatEnabled,
            'service_type' => $this->wanServiceType,
            'wifi_role' => $this->wanWifiRole,
            'pppoe_username_strategy' => $this->wanMode === 'pppoe' ? $this->pppoeUsernameStrategy : null,
            'pppoe_username_static' => $this->wanMode === 'pppoe' && $this->pppoeUsernameStrategy === 'static' ? $this->pppoeUsernameStatic : null,
            'pppoe_password_strategy' => $this->wanMode === 'pppoe' ? $this->pppoePasswordStrategy : null,
            'pppoe_password_static' => $this->pppoePasswordStatic,  // service: blank+update = pertahankan
            'static_ip' => $this->wanMode === 'static' ? $this->staticIp : null,
            'static_gateway' => $this->wanMode === 'static' ? $this->staticGateway : null,
            'static_netmask' => $this->wanMode === 'static' ? $this->staticNetmask : null,
            'static_dns' => $this->wanMode === 'static' && $this->staticDns !== '' ? $this->staticDns : null,
        ];

        $wanBridge = $this->hasBridge ? [
            'vlan' => (int) $this->bridgeVlan,
            'service_type' => $this->bridgeServiceType,
            'wifi_role' => $this->bridgeWifiRole,
        ] : null;

        $packageLinks = [];
        foreach ($this->linkedPackageIds as $pkgId) {
            $packageLinks[(int) $pkgId] = in_array((int) $pkgId, array_map('intval', $this->defaultPackageIds), true);
        }

        if ($this->editingId !== null) {
            $service->update(ProvisioningProfile::findOrFail($this->editingId), $profileData, $wanInternet, $wanBridge, $this->ssids, $packageLinks);
        } else {
            $service->create($profileData, $wanInternet, $wanBridge, $this->ssids, $packageLinks);
        }

        $this->cancel();
    }

    public function delete(int $id, ProvisioningProfileService $service): void
    {
        $this->authorize('manage', ProvisioningProfile::class);
        $service->delete(ProvisioningProfile::findOrFail($id));
    }

    /**
     * Contoh hasil resolve placeholder {cid}/{name} memakai satu pelanggan
     * contoh (tenant saat ini) — ditampilkan di form SSID.
     */
    public function ssidSampleResolve(string $template): string
    {
        $customer = Customer::query()->orderBy('id')->first();
        if ($customer === null) {
            return $template;
        }

        return app(SsidNameResolver::class)->resolve($template, $customer);
    }

    public function render()
    {
        $profiles = ProvisioningProfile::query()
            ->with(['wanInternet:id,provisioning_profile_id,wan_mode', 'wanBridge:id,provisioning_profile_id'])
            ->withCount(['pppPackages', 'cpeDevices', 'ssids'])
            ->when($this->search, fn ($q) => $q->where(fn ($sub) => $sub->where('name', 'like', "%{$this->search}%")->orWhere('slug', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.network.provisioning-profile-index', [
            'profiles' => $profiles,
            'packageOptions' => PppPackage::query()->orderBy('name')->get(['id', 'name']),
            'canManage' => auth()->user()->can('manage', ProvisioningProfile::class),
        ]);
    }
}
