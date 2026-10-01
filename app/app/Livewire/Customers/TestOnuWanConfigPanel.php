<?php

namespace App\Livewire\Customers;

use App\Enums\TestOnuConfigMethod;
use App\Enums\TestOnuMode;
use App\Enums\TestOnuWanMode;
use App\Models\Customer;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\TestOnuWanConfig;
use App\Services\Network\TestCredentialSyncService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * v0.23.5 (Opsi B) — panel "Update ONU Mode" + "Attached VLANs" gaya
 * SmartOLT, di-embed di halaman customer detail HANYA untuk customer
 * is_test_fixture=true. TEST-ONLY.
 *
 * Dua tombol TERPISAH, sengaja:
 * - "Simpan Konfigurasi" — persist test_onu_wan_configs + attached vlans
 *   SAJA, TIDAK menyentuh OLT.
 * - "Terapkan ke ONU" — panggil TestCredentialSyncService::applyWanConfig()
 *   yang BENAR-BENAR menulis ke OLT (delete+recreate). Dipisah supaya
 *   menyimpan konfigurasi tidak otomatis mengeksekusi perubahan live.
 */
class TestOnuWanConfigPanel extends Component
{
    public Customer $customer;

    public ?int $packageId = null;

    public string $onuMode = 'routing';

    public string $wanMode = 'pppoe';

    public string $configMethod = 'omci';

    public string $pppoeUsername = '';

    public string $pppoePassword = '';

    /** @var array<int> */
    public array $selectedVlans = [];

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function mount(Customer $customer): void
    {
        $this->customer = $customer;

        $config = $customer->testOnuWanConfig()->with('attachedVlans')->first();
        if ($config) {
            $this->packageId = $config->package_id;
            $this->onuMode = $config->onu_mode->value;
            $this->wanMode = $config->wan_mode->value;
            $this->configMethod = $config->config_method->value;
            $this->pppoeUsername = $config->pppoe_username ?? '';
            // Password sengaja TIDAK di-prefill (pola masked secret) — kosong
            // berarti "pertahankan yang tersimpan" saat save.
            $this->selectedVlans = $config->attachedVlans->pluck('vlan_id')
                ->map(fn ($v) => (int) $v)->all();
        }

        // VLAN 9 (remote mgmt) selalu tercentang (terkunci di UI + enforce
        // di service).
        if (! in_array(TestCredentialSyncService::MANDATORY_ATTACHED_VLAN, $this->selectedVlans, true)) {
            $this->selectedVlans[] = TestCredentialSyncService::MANDATORY_ATTACHED_VLAN;
        }
    }

    /**
     * Dropdown paket → VLAN (dinamis, semua PppPackage aktif yang grup-nya
     * punya VLAN di interface_name). Lihat docs/omci/onu-test-ui-design.md §1.
     *
     * @return array<int, array{id:int,label:string,vlan:int}>
     */
    #[Computed]
    public function packageOptions(): array
    {
        return PppPackage::withoutGlobalScopes()
            ->where('is_active', true)
            ->with('networkProfileGroup')
            ->get()
            ->map(function (PppPackage $p) {
                $iface = $p->networkProfileGroup?->interface_name;
                if (! $iface || ! preg_match('/^vlan(\d+)-/', $iface, $m)) {
                    return null; // paket tanpa VLAN disembunyikan (OPEN-1)
                }

                return ['id' => $p->id, 'label' => "{$p->name} (VLAN {$m[1]})", 'vlan' => (int) $m[1]];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Daftar VLAN untuk multi-select Attached VLANs — semua
     * NetworkProfileGroup ppp yang punya interface_name, + VLAN 9 (mgmt).
     *
     * @return array<int, array{vlan:int,label:string,locked:bool}>
     */
    #[Computed]
    public function vlanOptions(): array
    {
        $options = [[
            'vlan' => TestCredentialSyncService::MANDATORY_ATTACHED_VLAN,
            'label' => 'VLAN 9 — Remote Management (wajib)',
            'locked' => true,
        ]];

        $groups = NetworkProfileGroup::withoutGlobalScopes()
            ->whereNotNull('interface_name')
            ->get();
        $seen = [TestCredentialSyncService::MANDATORY_ATTACHED_VLAN];
        foreach ($groups as $g) {
            if (! preg_match('/^vlan(\d+)-/', (string) $g->interface_name, $m)) {
                continue;
            }
            $vlan = (int) $m[1];
            if (in_array($vlan, $seen, true)) {
                continue;
            }
            $seen[] = $vlan;
            $options[] = ['vlan' => $vlan, 'label' => "VLAN {$vlan} — {$g->interface_name}", 'locked' => false];
        }

        return $options;
    }

    public function save(): void
    {
        $this->resetMessages();
        $this->authorizeTestFixture();

        $this->validate([
            'packageId' => 'nullable|integer|exists:ppp_packages,id',
            'onuMode' => 'required|in:routing,bridging',
            'wanMode' => 'required|in:pppoe,dhcp,static,webpage',
            'configMethod' => 'required|in:omci,tr069',
            'pppoeUsername' => 'nullable|string|max:128',
            'pppoePassword' => 'nullable|string|max:128',
            'selectedVlans' => 'array',
            'selectedVlans.*' => 'integer',
        ]);

        $vlan = null;
        if ($this->packageId) {
            $pkg = PppPackage::withoutGlobalScopes()->with('networkProfileGroup')->find($this->packageId);
            if ($pkg?->networkProfileGroup?->interface_name
                && preg_match('/^vlan(\d+)-/', $pkg->networkProfileGroup->interface_name, $m)) {
                $vlan = (int) $m[1];
            }
        }

        $config = TestOnuWanConfig::firstOrNew(['customer_id' => $this->customer->id]);
        $config->package_id = $this->packageId;
        $config->vlan_pppoe = $vlan;
        $config->onu_mode = TestOnuMode::from($this->onuMode);
        $config->wan_mode = TestOnuWanMode::from($this->wanMode);
        $config->config_method = TestOnuConfigMethod::from($this->configMethod);
        $config->pppoe_username = $this->pppoeUsername ?: null;
        // Kosong = pertahankan password tersimpan (masked secret convention).
        if ($this->pppoePassword !== '') {
            $config->pppoe_password = $this->pppoePassword;
        }
        $config->save();

        // Sync attached vlans — VLAN 9 selalu disertakan (enforce di service
        // juga, ini lapisan UI/persist).
        $vlans = collect($this->selectedVlans)->map(fn ($v) => (int) $v)->push(TestCredentialSyncService::MANDATORY_ATTACHED_VLAN)->unique()->values();
        $groupByVlan = NetworkProfileGroup::withoutGlobalScopes()->whereNotNull('interface_name')->get()
            ->mapWithKeys(function (NetworkProfileGroup $g) {
                preg_match('/^vlan(\d+)-/', (string) $g->interface_name, $m);

                return $m ? [(int) $m[1] => $g->id] : [];
            });
        $config->attachedVlans()->delete();
        foreach ($vlans as $v) {
            $config->attachedVlans()->create([
                'vlan_id' => $v,
                'network_profile_group_id' => $groupByVlan[$v] ?? null,
            ]);
        }

        $this->pppoePassword = '';
        $this->statusMessage = 'Konfigurasi WAN test tersimpan (belum diterapkan ke ONU).';
    }

    public function apply(TestCredentialSyncService $service): void
    {
        $this->resetMessages();
        $this->authorizeTestFixture();

        try {
            $result = $service->applyWanConfig($this->customer->fresh());
            $extra = empty($result['extra_flow_vlans']) ? '' : ' (+flow '.implode(',', $result['extra_flow_vlans']).')';
            $this->statusMessage = "Diterapkan ke ONU: VLAN PPPoE {$result['vlan_pppoe']}, Framed-Pool {$result['framed_pool']}{$extra}.";
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    private function authorizeTestFixture(): void
    {
        abort_unless($this->customer->is_test_fixture, 403);
    }

    private function resetMessages(): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;
    }

    public function render()
    {
        return view('livewire.customers.test-onu-wan-config-panel');
    }
}
