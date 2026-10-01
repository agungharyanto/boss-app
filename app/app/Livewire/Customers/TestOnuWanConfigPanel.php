<?php

namespace App\Livewire\Customers;

use App\Enums\TestOnuConfigMethod;
use App\Enums\TestOnuMode;
use App\Enums\TestOnuWanMode;
use App\Models\Customer;
use App\Models\NetworkProfileGroup;
use App\Models\TestOnuWanConfig;
use App\Services\Network\TestCredentialSyncService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * v0.23.5 (revisi OPSI a) — panel "Konfigurasi WAN ONU (Test)", di-embed
 * di halaman Detail Perangkat CPE (via $device->customer) HANYA untuk
 * customer is_test_fixture=true. TEST-ONLY.
 *
 * Paket/VLAN, PPPoE Username, PPPoE Password = READ-ONLY, DIDERIVE LIVE
 * dari customer (tidak disimpan di test_onu_wan_configs):
 * - Paket/VLAN: customers.ppp_package_id (diubah dari Daftar Pelanggan).
 * - Username: {cid}@ppp.bajastu.id. Password: konstanta sistem.
 * Yang masih bisa diedit di panel ini: onu_mode / wan_mode / config_method
 * / attached_vlans (tidak ada tempat lain untuk mengaturnya).
 */
class TestOnuWanConfigPanel extends Component
{
    public Customer $customer;

    public string $onuMode = 'routing';

    public string $wanMode = 'pppoe';

    public string $configMethod = 'omci';

    /** @var array<int> */
    public array $selectedVlans = [];

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function mount(Customer $customer): void
    {
        $this->customer = $customer;

        $config = $customer->testOnuWanConfig()->with('attachedVlans')->first();
        if ($config) {
            $this->onuMode = $config->onu_mode->value;
            $this->wanMode = $config->wan_mode->value;
            $this->configMethod = $config->config_method->value;
            $this->selectedVlans = $config->attachedVlans->pluck('vlan_id')
                ->map(fn ($v) => (int) $v)->all();
        }

        if (! in_array(TestCredentialSyncService::MANDATORY_ATTACHED_VLAN, $this->selectedVlans, true)) {
            $this->selectedVlans[] = TestCredentialSyncService::MANDATORY_ATTACHED_VLAN;
        }
    }

    /**
     * Parameter WAN turunan LIVE (read-only display) — satu sumber sama
     * dengan applyWanConfig(). Lihat TestCredentialSyncService::deriveLiveWanParams().
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function live(): array
    {
        return app(TestCredentialSyncService::class)->deriveLiveWanParams($this->customer);
    }

    /**
     * URL edit paket pelanggan (Daftar Pelanggan / Detail Pelanggan).
     */
    #[Computed]
    public function editPackageUrl(): string
    {
        return route('web.customers.show', $this->customer);
    }

    /**
     * Daftar VLAN untuk multi-select Attached VLANs — semua
     * NetworkProfileGroup ppp yang punya interface_name + VLAN 9 (mgmt).
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

        $seen = [TestCredentialSyncService::MANDATORY_ATTACHED_VLAN];
        foreach (NetworkProfileGroup::withoutGlobalScopes()->whereNotNull('interface_name')->get() as $g) {
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
            'onuMode' => 'required|in:routing,bridging',
            'wanMode' => 'required|in:pppoe,dhcp,static,webpage',
            'configMethod' => 'required|in:omci,tr069',
            'selectedVlans' => 'array',
            'selectedVlans.*' => 'integer',
        ]);

        $config = TestOnuWanConfig::firstOrNew(['customer_id' => $this->customer->id]);
        $config->onu_mode = TestOnuMode::from($this->onuMode);
        $config->wan_mode = TestOnuWanMode::from($this->wanMode);
        $config->config_method = TestOnuConfigMethod::from($this->configMethod);
        $config->save();

        // Attached vlans — VLAN 9 selalu disertakan (enforce lapis UI/persist;
        // service juga meng-enforce).
        $vlans = collect($this->selectedVlans)->map(fn ($v) => (int) $v)
            ->push(TestCredentialSyncService::MANDATORY_ATTACHED_VLAN)->unique()->values();
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
