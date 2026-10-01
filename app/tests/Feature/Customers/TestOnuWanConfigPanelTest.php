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
 * v0.23.5 (revisi OPSI a) — panel WAN config test. Paket/VLAN/username/
 * password DIDERIVE LIVE dari customer (read-only di panel); hanya
 * onu_mode/wan_mode/config_method/attached_vlans yang diedit. OLT di-mock
 * (TestCredentialSyncService) untuk aksi apply — tidak pernah sentuh OLT.
 */
class TestOnuWanConfigPanelTest extends TestCase
{
    use RefreshDatabase;

    private function attachVlanPackage(Customer $customer, string $interfaceName): PppPackage
    {
        $nas = Nas::factory()->create();
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'PPPOE-REMOTE']);
        $group = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id,
            'type' => NetworkProfileGroupType::Ppp,
            'customer_ip_pool_id' => $pool->id,
            'interface_name' => $interfaceName,
        ]);
        $package = PppPackage::factory()->create(['network_profile_group_id' => $group->id]);
        $customer->forceFill(['ppp_package_id' => $package->id])->save();

        return $package;
    }

    private function makeTestCustomer(): Customer
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
        $customer = $this->makeTestCustomer();

        Livewire::test(TestOnuWanConfigPanel::class, ['customer' => $customer])
            ->assertSet('selectedVlans', fn ($v) => in_array(9, $v, true));
    }

    public function test_save_persists_only_mode_fields_and_forces_vlan_9(): void
    {
        $customer = $this->makeTestCustomer();

        Livewire::test(TestOnuWanConfigPanel::class, ['customer' => $customer])
            ->set('onuMode', 'routing')
            ->set('wanMode', 'pppoe')
            ->set('configMethod', 'omci')
            ->set('selectedVlans', [172]) // sengaja tanpa 9
            ->call('save')
            ->assertHasNoErrors();

        $config = TestOnuWanConfig::where('customer_id', $customer->id)->with('attachedVlans')->first();
        $this->assertNotNull($config);
        $this->assertSame('omci', $config->config_method->value);
        $vlans = $config->attachedVlans->pluck('vlan_id')->all();
        $this->assertContains(9, $vlans);   // dipaksa masuk (service/persist enforce)
        $this->assertContains(172, $vlans);
    }

    public function test_live_derive_shows_package_vlan_and_username(): void
    {
        $customer = $this->makeTestCustomer();
        $this->attachVlanPackage($customer, 'vlan111-PPPoE-10Mbps-Loyalis');

        $component = Livewire::test(TestOnuWanConfigPanel::class, ['customer' => $customer->fresh()]);
        $live = $component->instance()->live();

        $this->assertTrue($live['has_vlan_package']);
        $this->assertSame(111, $live['vlan_pppoe']);
        $this->assertSame('PPPOE-REMOTE', $live['framed_pool']);
        $this->assertSame("{$customer->cid}@ppp.bajastu.id", $live['username']);
        $this->assertSame('wifijadipasti', $live['password']);
        // Paket/VLAN & username muncul di HTML read-only + link ke Daftar Pelanggan.
        $component->assertSee('VLAN 111')->assertSee("{$customer->cid}@ppp.bajastu.id")
            ->assertSee('Ubah paket di Daftar Pelanggan');
    }

    public function test_no_vlan_package_shows_friendly_message_not_error(): void
    {
        $customer = $this->makeTestCustomer(); // tanpa ppp_package_id ber-VLAN

        $component = Livewire::test(TestOnuWanConfigPanel::class, ['customer' => $customer]);

        $this->assertFalse($component->instance()->live()['has_vlan_package']);
        $component->assertSee('belum punya paket dengan VLAN');
    }

    public function test_changing_customer_package_reflects_live_without_manual_sync(): void
    {
        $customer = $this->makeTestCustomer();
        $this->attachVlanPackage($customer, 'vlan111-PPPoE-10Mbps-Loyalis');
        $this->assertSame(111, Livewire::test(TestOnuWanConfigPanel::class, ['customer' => $customer->fresh()])
            ->instance()->live()['vlan_pppoe']);

        // Ganti paket pelanggan (seperti dari Daftar Pelanggan) ke VLAN 10.
        $this->attachVlanPackage($customer, 'vlan10-PPPoE');

        // Reload panel → VLAN baru muncul tanpa langkah sinkronisasi manual.
        $this->assertSame(10, Livewire::test(TestOnuWanConfigPanel::class, ['customer' => $customer->fresh()])
            ->instance()->live()['vlan_pppoe']);
    }

    public function test_apply_delegates_to_service_and_shows_result(): void
    {
        $customer = $this->makeTestCustomer();

        $mock = $this->createMock(TestCredentialSyncService::class);
        $mock->method('deriveLiveWanParams')->willReturn(['has_vlan_package' => true, 'vlan_pppoe' => 111, 'framed_pool' => 'PPPOE-REMOTE', 'username' => 'x@ppp.bajastu.id', 'password' => 'wifijadipasti', 'package_name' => 'PPPoE-Remote', 'group_id' => 1, 'message' => null]);
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
        $customer = $this->makeTestCustomer();

        $mock = $this->createMock(TestCredentialSyncService::class);
        $mock->method('deriveLiveWanParams')->willReturn(['has_vlan_package' => true, 'vlan_pppoe' => 111, 'framed_pool' => 'PPPOE-REMOTE', 'username' => 'x@ppp.bajastu.id', 'password' => 'wifijadipasti', 'package_name' => 'PPPoE-Remote', 'group_id' => 1, 'message' => null]);
        $mock->method('applyWanConfig')->willThrowException(new \RuntimeException('delete_onu gagal: boom'));
        $this->app->instance(TestCredentialSyncService::class, $mock);

        Livewire::test(TestOnuWanConfigPanel::class, ['customer' => $customer])
            ->call('apply')
            ->assertSet('errorMessage', fn ($m) => str_contains((string) $m, 'delete_onu gagal'));
    }
}
