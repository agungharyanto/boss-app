<?php

namespace Tests\Feature\Network;

use App\Enums\PonMode;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\OltDevice;
use App\Models\ProvisioningAdapter;
use App\Services\Network\CtcomActivationService;
use App\Services\Network\GenieAcsClientService;
use App\Services\Network\Provisioning\Cpe\CtcomCpeAdapter;
use App\Services\Network\Provisioning\Cpe\UnsupportedCpeAdapter;
use App\Services\Network\Provisioning\CpeAdapterRegistry;
use App\Services\Network\Provisioning\Exceptions\UnsupportedAdapterOperation;
use App\Services\Network\Provisioning\Olt\CtcomOltAdapter;
use App\Services\Network\Provisioning\Olt\ZteC300OltAdapter;
use App\Services\Network\Provisioning\OltAdapterRegistry;
use Database\Seeders\ProvisioningAdapterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.23.8.1 — pondasi dua registry adapter ZTP (nol sentuh device).
 */
class ProvisioningAdapterRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function cpe(): CpeAdapterRegistry
    {
        return app(CpeAdapterRegistry::class);
    }

    private function olt(): OltAdapterRegistry
    {
        return app(OltAdapterRegistry::class);
    }

    public function test_cpe_registry_resolves_ctcom_as_supported(): void
    {
        $adapter = $this->cpe()->for('ctcom');

        $this->assertInstanceOf(CtcomCpeAdapter::class, $adapter);
        $this->assertTrue($adapter->isSupported());
        $this->assertSame('tr069', $adapter->transport());
        $this->assertTrue($adapter->capabilities()->operations['wan_internet']);
        $this->assertTrue($adapter->capabilities()->operations['ssid']);
        // admisi SENGAJA tidak ada di CPE adapter (murni sisi-OLT) — key 'admit' absen.
        $this->assertArrayNotHasKey('admit', $adapter->capabilities()->operations);
    }

    public function test_cpe_registry_returns_unsupported_stub_for_unverified_brands(): void
    {
        foreach (['huawei', 'cmcc', 'zte_generic'] as $vendorKey) {
            $adapter = $this->cpe()->for($vendorKey);
            $this->assertInstanceOf(UnsupportedCpeAdapter::class, $adapter);
            $this->assertFalse($adapter->isSupported());
        }
    }

    public function test_unsupported_cpe_adapter_rejects_operations_with_clear_message(): void
    {
        $adapter = $this->cpe()->for('huawei');

        $this->expectException(UnsupportedAdapterOperation::class);
        $this->expectExceptionMessageMatches('/belum didukung.*pushSsid|pushSsid.*belum didukung/s');

        $device = new CpeDevice;
        $adapter->pushSsid($device, []);
    }

    public function test_cpe_registry_throws_for_unknown_vendor(): void
    {
        $this->expectException(UnsupportedAdapterOperation::class);
        $this->cpe()->for('nokia_xyz');
    }

    public function test_ctcom_cpe_pushssid_requires_genieacs_device_id(): void
    {
        // pushSsid sudah diimplementasi (v0.23.8.4) — tanpa genieacs_device_id
        // ia menolak jelas (bukan AdapterNotYetImplemented lagi).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/genieacs_device_id/');
        $this->cpe()->for('ctcom')->pushSsid(new CpeDevice, []);
    }

    public function test_olt_registry_resolves_ctcom_olt_with_pon_mode_per_vendor(): void
    {
        $g02 = $this->olt()->for('hsgq_g02id');
        $e04 = $this->olt()->for('hsgq_e04id');

        $this->assertInstanceOf(CtcomOltAdapter::class, $g02);
        $this->assertInstanceOf(CtcomOltAdapter::class, $e04);
        $this->assertSame(PonMode::Gpon, $g02->ponMode());
        $this->assertSame(PonMode::Epon, $e04->ponMode());
        // WAN internet CT-COM OLT = device-driven TR-069, bukan OMCI.
        $this->assertSame('tr069_device_driven', $g02->capabilities()->wanInternetVia);
    }

    public function test_ctcom_olt_wan_internet_omci_is_rejected_as_device_driven(): void
    {
        $this->expectException(UnsupportedAdapterOperation::class);
        $this->olt()->for('hsgq_g02id')->pushWanInternetOmci(new OltDevice, []);
    }

    public function test_olt_registry_resolves_zte_c300_with_omci_wan_and_auto_save(): void
    {
        $zte = $this->olt()->for('zte_c300');

        $this->assertInstanceOf(ZteC300OltAdapter::class, $zte);
        $this->assertSame(PonMode::Gpon, $zte->ponMode());
        $this->assertSame('omci', $zte->capabilities()->wanInternetVia);
        $this->assertTrue($zte->capabilities()->operations['wan_internet_omci']);
        $this->assertFalse($zte->capabilities()->operations['save']); // auto-write
    }

    public function test_olt_registry_throws_for_unknown_vendor(): void
    {
        $this->expectException(UnsupportedAdapterOperation::class);
        $this->olt()->for('ubiquiti_olt');
    }

    public function test_pon_mode_mapping_from_vendor_key(): void
    {
        $this->assertSame(PonMode::Gpon, PonMode::fromVendorKey('hsgq_g02id'));
        $this->assertSame(PonMode::Epon, PonMode::fromVendorKey('hsgq_e04id'));
        $this->assertSame(PonMode::Gpon, PonMode::fromVendorKey('zte_c300'));

        $this->expectException(InvalidArgumentException::class);
        PonMode::fromVendorKey('unknown_vendor');
    }

    public function test_seeder_mirrors_every_adapter_into_the_table(): void
    {
        $this->seed(ProvisioningAdapterSeeder::class);

        // 4 CPE (ctcom, huawei, cmcc, zte_generic) + 3 OLT (g02id, e04id, zte_c300)
        $this->assertSame(7, ProvisioningAdapter::count());

        $ctcomCpe = ProvisioningAdapter::where('layer', 'cpe')->where('vendor_key', 'ctcom')->first();
        $this->assertNotNull($ctcomCpe);
        $this->assertTrue($ctcomCpe->is_supported);
        $this->assertNull($ctcomCpe->pon_mode); // CPE = per-brand, OLT-independent

        $huaweiCpe = ProvisioningAdapter::where('layer', 'cpe')->where('vendor_key', 'huawei')->first();
        $this->assertNotNull($huaweiCpe);
        $this->assertFalse($huaweiCpe->is_supported);

        $zteOlt = ProvisioningAdapter::where('layer', 'olt')->where('vendor_key', 'zte_c300')->first();
        $this->assertNotNull($zteOlt);
        $this->assertSame(PonMode::Gpon, $zteOlt->pon_mode);
        $this->assertSame('omci', $zteOlt->capabilities['wan_internet_via']);

        $e04Olt = ProvisioningAdapter::where('layer', 'olt')->where('vendor_key', 'hsgq_e04id')->first();
        $this->assertNotNull($e04Olt);
        $this->assertSame(PonMode::Epon, $e04Olt->pon_mode);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(ProvisioningAdapterSeeder::class);
        $this->seed(ProvisioningAdapterSeeder::class);

        $this->assertSame(7, ProvisioningAdapter::count());
    }

    // ---- delegasi adapter -> CtcomActivationService (v0.23.8.2) ----------

    public function test_ctcom_cpe_adapter_delegates_wan_bridge_to_activation_service(): void
    {
        $service = $this->createMock(CtcomActivationService::class);
        $service->expects($this->once())->method('verifyBridgePresent')
            ->with('DEV-GENIE', 172)->willReturn(true);
        $this->app->instance(CtcomActivationService::class, $service);

        $cpe = new CpeDevice;
        $cpe->genieacs_device_id = 'DEV-GENIE';

        $result = $this->cpe()->for('ctcom')->pushWanBridge($cpe, []);

        $this->assertTrue($result['bridge_present']);
        $this->assertTrue($result['device_driven']);
    }

    public function test_ctcom_olt_adapter_delegates_save_config_to_activation_service(): void
    {
        $service = $this->createMock(CtcomActivationService::class);
        $service->expects($this->once())->method('saveConfig')
            ->willReturn(['saved' => true, 'raw_excerpt' => 'Configuration saved successfully']);
        $this->app->instance(CtcomActivationService::class, $service);

        $result = $this->olt()->for('hsgq_e04id')->saveConfig(new OltDevice);

        $this->assertTrue($result['saved']);
    }

    // ---- pushWanBridge: set X_CT-COM_LanInterface, instance di-derive dari
    //      WLAN yang GENUINELY ada (v0.23.8.5b — gap LAN-binding ditutup) ------

    /**
     * Tree CT-COM dgn bridge VID172 (WCD.3 PPPoE_Bridged) + `$wlanCount` instance
     * WLANConfiguration (1..N). 4 → device 2.4g-only; 8 → device dual-band.
     *
     * @return array<string, mixed>
     */
    private function ctcomBridgeDevice(int $wlanCount): array
    {
        $wlan = [];
        for ($i = 1; $i <= $wlanCount; $i++) {
            $wlan[$i] = ['SSID' => ['_value' => "SSID{$i}"]];
        }

        return [
            '_id' => 'DEV-GENIE',
            'InternetGatewayDevice' => [
                'WANDevice' => ['1' => ['WANConnectionDevice' => [
                    '3' => [
                        'WANPPPConnection' => ['1' => [
                            'Name' => ['_value' => '3_Other_B_VID_172'],
                            'ConnectionType' => ['_value' => 'PPPoE_Bridged'],
                        ]],
                        'X_CT-COM_WANGponLinkConfig' => ['VLANIDMark' => ['_value' => 172]],
                    ],
                ]]],
                'LANDevice' => ['1' => ['WLANConfiguration' => $wlan]],
            ],
        ];
    }

    /**
     * @return array{result: array<string, mixed>, tasks: list<array<string, mixed>>}
     */
    private function runPushWanBridge(int $wlanCount): array
    {
        $genie = $this->createMock(GenieAcsClientService::class);
        $genie->method('findDeviceById')->willReturn($this->ctcomBridgeDevice($wlanCount));
        $tasks = [];
        $genie->method('sendTask')->willReturnCallback(function (string $id, array $task, bool $cr) use (&$tasks) {
            $tasks[] = $task;

            return ['task_id' => 't1'];
        });
        $this->app->instance(GenieAcsClientService::class, $genie);

        $cpe = new CpeDevice;
        $cpe->genieacs_device_id = 'DEV-GENIE';

        return ['result' => $this->cpe()->for('ctcom')->pushWanBridge($cpe, ['bridge_vlan' => 172]), 'tasks' => $tasks];
    }

    private function lanInterfaceValueFrom(array $tasks): ?string
    {
        foreach ($tasks as $task) {
            if (($task['name'] ?? null) !== 'setParameterValues') {
                continue;
            }
            foreach ($task['parameterValues'] as $pv) {
                if (str_ends_with((string) $pv[0], '.X_CT-COM_LanInterface')) {
                    return (string) $pv[1];
                }
            }
        }

        return null;
    }

    public function test_pushwanbridge_binds_only_existing_guest_instance_on_a_4_wlan_device(): void
    {
        ['result' => $result, 'tasks' => $tasks] = $this->runPushWanBridge(4);

        $this->assertTrue($result['bridge_present']);
        $this->assertTrue($result['lan_binding_set']);
        $this->assertSame([4], $result['lan_binding_instances']);
        $this->assertSame(
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.4',
            $this->lanInterfaceValueFrom($tasks),
        );
        // DHCPEnable=false ikut terkirim
        $flat = array_merge(...array_map(fn ($t) => $t['parameterValues'] ?? [], $tasks));
        $this->assertContains('InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANPPPConnection.1.X_CT-COM_LanInterface-DHCPEnable', array_column($flat, 0));
    }

    public function test_pushwanbridge_binds_instances_4_and_8_on_an_8_wlan_device(): void
    {
        ['result' => $result, 'tasks' => $tasks] = $this->runPushWanBridge(8);

        $this->assertTrue($result['lan_binding_set']);
        $this->assertSame([4, 8], $result['lan_binding_instances']);
        $this->assertSame(
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.4,InternetGatewayDevice.LANDevice.1.WLANConfiguration.8',
            $this->lanInterfaceValueFrom($tasks),
        );
    }

    // ---- disableUnboundWlan: matikan WLAN yg tak dipakai, jangan sentuh yg dipakai

    /**
     * @param  list<int>  $boundInstances  instance WLAN yang di-bind LanInterface WAN
     * @return array<string, mixed>
     */
    private function wlanDeviceWithBoundInstances(int $count, array $boundInstances): array
    {
        $wlan = [];
        for ($i = 1; $i <= $count; $i++) {
            $wlan[$i] = ['SSID' => ['_value' => "SSID{$i}"], 'Enable' => ['_value' => $i === 1]];
        }
        $lanIf = implode(',', array_map(
            static fn (int $i): string => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$i}",
            $boundInstances,
        ));

        return [
            '_id' => 'DEV-GENIE',
            'InternetGatewayDevice' => [
                'WANDevice' => ['1' => ['WANConnectionDevice' => [
                    '3' => ['WANPPPConnection' => ['1' => [
                        'Name' => ['_value' => '3_Other_B_VID_172'],
                        'X_CT-COM_LanInterface' => ['_value' => $lanIf],
                    ]]],
                ]]],
                'LANDevice' => ['1' => ['WLANConfiguration' => $wlan]],
            ],
        ];
    }

    /**
     * @param  list<int>  $boundInstances
     * @param  array<int, array<string, mixed>>  $ssidSpecs
     * @return array{result: array<string, mixed>, tasks: list<array<string, mixed>>}
     */
    private function runDisableUnbound(int $count, array $boundInstances, array $ssidSpecs): array
    {
        $genie = $this->createMock(GenieAcsClientService::class);
        $genie->method('findDeviceById')->willReturn($this->wlanDeviceWithBoundInstances($count, $boundInstances));
        $tasks = [];
        $genie->method('sendTask')->willReturnCallback(function (string $id, array $task, bool $cr) use (&$tasks) {
            $tasks[] = $task;

            return ['task_id' => 't1'];
        });
        $this->app->instance(GenieAcsClientService::class, $genie);

        $cpe = new CpeDevice;
        $cpe->genieacs_device_id = 'DEV-GENIE';

        return ['result' => $this->cpe()->for('ctcom')->disableUnboundWlan($cpe, $ssidSpecs), 'tasks' => $tasks];
    }

    public function test_disable_unbound_wlan_4_wlan_device_turns_off_only_unused(): void
    {
        // profil guest-only (instance 4), bridge bind WLAN.4 → kept {4}, disable {1,2,3}
        ['result' => $r, 'tasks' => $tasks] = $this->runDisableUnbound(4, [4], [
            ['wifi_role' => 'guest_wifi', 'band' => '2.4g'],
        ]);

        $this->assertSame([1, 2, 3], $r['disabled']);
        $this->assertContains(4, $r['kept']);

        $names = array_column($tasks[0]['parameterValues'], 0);
        foreach ([1, 2, 3] as $i) {
            $this->assertContains("InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$i}.Enable", $names);
        }
        $this->assertNotContains('InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.Enable', $names);
        foreach ($tasks[0]['parameterValues'] as $pv) {
            $this->assertFalse($pv[1]); // semua Enable=false
        }
    }

    public function test_disable_unbound_wlan_8_wlan_device_keeps_covered_and_bound(): void
    {
        // profil primary+guest 2.4g → covered {1,4}; bound {4}; disable {2,3,5,6,7,8}
        ['result' => $r] = $this->runDisableUnbound(8, [4], [
            ['wifi_role' => 'primary_wifi', 'band' => '2.4g'],
            ['wifi_role' => 'guest_wifi', 'band' => '2.4g'],
        ]);

        $this->assertSame([2, 3, 5, 6, 7, 8], $r['disabled']);
        $this->assertContains(1, $r['kept']);
        $this->assertContains(4, $r['kept']);
    }

    public function test_ctcom_e04id_apply_naming_is_a_documented_noop(): void
    {
        // E04ID: name+desc sudah via bind-onu → naming OLT = no-op, TIDAK memanggil
        // op naming OMCI G02ID (applyOmciNaming).
        $svc = $this->createMock(CtcomActivationService::class);
        $svc->expects($this->never())->method('applyOmciNaming');
        $this->app->instance(CtcomActivationService::class, $svc);

        $result = $this->olt()->for('hsgq_e04id')->applyNaming(new OltDevice, ['customer' => new Customer, 'sn' => 'ZICG2976A4FC']);

        $this->assertTrue($result['named']);
        $this->assertSame('noop_bind_onu', $result['mode']);
    }

    // ---- pushSsid guard 5GHz --------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $ssidSpecs
     * @return array{result: array<string, mixed>, tasks: list<array<string, mixed>>}
     */
    private function runPushSsid(int $wlanCount, array $ssidSpecs): array
    {
        $genie = $this->createMock(GenieAcsClientService::class);
        $genie->method('findDeviceById')->willReturn($this->ctcomBridgeDevice($wlanCount));
        $tasks = [];
        $genie->method('sendTask')->willReturnCallback(function (string $id, array $task, bool $cr) use (&$tasks) {
            $tasks[] = $task;

            return ['task_id' => 't1'];
        });
        $this->app->instance(GenieAcsClientService::class, $genie);

        $cpe = new CpeDevice;
        $cpe->genieacs_device_id = 'DEV-GENIE';

        return ['result' => $this->cpe()->for('ctcom')->pushSsid($cpe, $ssidSpecs), 'tasks' => $tasks];
    }

    public function test_pushssid_skips_5g_spec_on_a_4_wlan_device_without_blind_addobject(): void
    {
        // device 4-WLAN (tak ada instance 5G 5-8), spec guest 5g → di-skip + alasan,
        // TIDAK AddObject buta.
        ['result' => $r, 'tasks' => $tasks] = $this->runPushSsid(4, [
            ['wifi_role' => 'guest_wifi', 'band' => '5g', 'ssid_name' => 'X', 'security_mode' => 'open', 'password' => null],
        ]);

        $this->assertSame([], $r['configured']);
        $this->assertFalse($r['addobject_issued']);
        $this->assertCount(1, $r['skipped']);
        $this->assertSame(8, $r['skipped'][0]['instance']);
        $this->assertSame('5g', $r['skipped'][0]['band']);
        $this->assertStringContainsString('5G', $r['skipped'][0]['reason']);
        // tak ada task addObject terkirim
        $this->assertNotContains('addObject', array_column($tasks, 'name'));
    }

    public function test_pushssid_5g_spec_on_an_8_wlan_device_runs_normally(): void
    {
        // device 8-WLAN (instance 8 ada), spec guest 5g → configured, tanpa skip.
        ['result' => $r, 'tasks' => $tasks] = $this->runPushSsid(8, [
            ['wifi_role' => 'guest_wifi', 'band' => '5g', 'ssid_name' => 'X', 'security_mode' => 'open', 'password' => null],
        ]);

        $this->assertSame([8], $r['configured']);
        $this->assertSame([], $r['skipped']);
        $this->assertFalse($r['addobject_issued']);
        $this->assertSame('setParameterValues', $tasks[0]['name']);
    }

    public function test_pushssid_2_4g_spec_on_a_4_wlan_device_runs_normally(): void
    {
        // device 4-WLAN, spec guest 2.4g (instance 4 ada) → configured, tanpa skip.
        ['result' => $r] = $this->runPushSsid(4, [
            ['wifi_role' => 'guest_wifi', 'band' => '2.4g', 'ssid_name' => 'X', 'security_mode' => 'open', 'password' => null],
        ]);

        $this->assertSame([4], $r['configured']);
        $this->assertSame([], $r['skipped']);
        $this->assertFalse($r['addobject_issued']);
    }
}
