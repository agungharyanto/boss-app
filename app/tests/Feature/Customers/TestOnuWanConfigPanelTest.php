<?php

namespace Tests\Feature\Customers;

use App\Enums\NetworkProfileGroupType;
use App\Livewire\Customers\TestOnuWanConfigPanel;
use App\Models\Customer;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\TestOnuWanConfig;
use App\Services\Network\TestCredentialSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.23.5 (Opsi B) — panel WAN config test. TIDAK memanggil OLT sungguhan
 * (TestCredentialSyncService di-mock untuk aksi apply).
 */
class TestOnuWanConfigPanelTest extends TestCase
{
    use RefreshDatabase;

    private function makePackageWithVlan(string $interfaceName): PppPackage
    {
        $nas = Nas::factory()->create();
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'PPPOE-REMOTE']);
        $group = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id,
            'type' => NetworkProfileGroupType::Ppp,
            'customer_ip_pool_id' => $pool->id,
            'interface_name' => $interfaceName,
        ]);

        return PppPackage::factory()->create(['network_profile_group_id' => $group->id]);
    }

    private function testCustomer(): Customer
    {
        return Customer::factory()->create([
            'is_test_fixture' => true,
            'test_onu_metadata' => [
                'olt_device_id' => 1,
                'pon_interface' => '1/3/12',
                'onu_id' => 4,
                'sn' => 'CMDCA45762D6',
                'vlan_mgmt' => 9,
                'vlan_bridge' => 172,
                'tcont_profile' => 'HomeFixed-10Mbps',
                'traffic_profile' => 'PPPoE-Remote',
            ],
        ]);
    }

    public function test_vlan_9_is_always_preselected_on_mount(): void
    {
        $customer = $this->testCustomer();

        Livewire::test(TestOnuWanConfigPanel::class, ['customer' => $customer])
            ->assertSet('selectedVlans', fn ($v) => in_array(9, $v, true));
    }

    public function test_save_persists_config_and_always_includes_vlan_9(): void
    {
        $customer = $this->testCustomer();
        $package = $this->makePackageWithVlan('vlan111-PPPoE-10Mbps-Loyalis');

        Livewire::test(TestOnuWanConfigPanel::class, ['customer' => $customer])
            ->set('packageId', $package->id)
            ->set('pppoeUsername', '2026090744@ppp.bajastu.id')
            ->set('pppoePassword', 'wifijadipasti')
            ->set('selectedVlans', [172]) // sengaja tanpa 9
            ->call('save')
            ->assertHasNoErrors();

        $config = TestOnuWanConfig::where('customer_id', $customer->id)->with('attachedVlans')->first();
        $this->assertNotNull($config);
        $this->assertSame(111, $config->vlan_pppoe);
        $this->assertSame('wifijadipasti', $config->pppoe_password);
        $vlans = $config->attachedVlans->pluck('vlan_id')->all();
        $this->assertContains(9, $vlans);   // dipaksa masuk
        $this->assertContains(172, $vlans);
    }

    public function test_empty_password_on_save_keeps_the_stored_one(): void
    {
        $customer = $this->testCustomer();
        TestOnuWanConfig::create([
            'customer_id' => $customer->id,
            'onu_mode' => 'routing', 'wan_mode' => 'pppoe', 'config_method' => 'omci',
            'pppoe_username' => 'u@ppp', 'pppoe_password' => 'keepme',
        ]);

        Livewire::test(TestOnuWanConfigPanel::class, ['customer' => $customer])
            ->set('pppoePassword', '') // kosong
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('keepme', TestOnuWanConfig::where('customer_id', $customer->id)->first()->pppoe_password);
    }

    public function test_apply_delegates_to_service_and_shows_result(): void
    {
        $customer = $this->testCustomer();

        $mock = $this->createMock(TestCredentialSyncService::class);
        $mock->expects($this->once())->method('applyWanConfig')
            ->willReturn(['vlan_pppoe' => 111, 'framed_pool' => 'PPPOE-REMOTE', 'extra_flow_vlans' => []]);
        $this->app->instance(TestCredentialSyncService::class, $mock);

        Livewire::test(TestOnuWanConfigPanel::class, ['customer' => $customer])
            ->call('apply')
            ->assertSet('errorMessage', null)
            ->assertSet('statusMessage', fn ($m) => str_contains((string) $m, 'VLAN PPPoE 111'));
    }

    public function test_apply_surfaces_service_error_without_throwing(): void
    {
        $customer = $this->testCustomer();

        $mock = $this->createMock(TestCredentialSyncService::class);
        $mock->method('applyWanConfig')->willThrowException(new \RuntimeException('delete_onu gagal: boom'));
        $this->app->instance(TestCredentialSyncService::class, $mock);

        Livewire::test(TestOnuWanConfigPanel::class, ['customer' => $customer])
            ->call('apply')
            ->assertSet('errorMessage', fn ($m) => str_contains((string) $m, 'delete_onu gagal'));
    }

    public function test_package_options_hide_packages_without_a_vlan(): void
    {
        $customer = $this->testCustomer();
        $withVlan = $this->makePackageWithVlan('vlan131-PPPoE-30Mbps-Loyalis');
        // Paket tanpa interface_name VLAN -> harus disembunyikan.
        $nas = Nas::factory()->create();
        $groupNoVlan = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id, 'type' => NetworkProfileGroupType::Ppp, 'interface_name' => null,
        ]);
        $withoutVlan = PppPackage::factory()->create(['network_profile_group_id' => $groupNoVlan->id]);

        $component = Livewire::test(TestOnuWanConfigPanel::class, ['customer' => $customer]);
        $ids = collect($component->instance()->packageOptions())->pluck('id')->all();

        $this->assertContains($withVlan->id, $ids);
        $this->assertNotContains($withoutVlan->id, $ids);
    }
}
