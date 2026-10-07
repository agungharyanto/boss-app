<?php

namespace Tests\Feature\Network;

use App\Enums\PonMode;
use App\Models\CpeDevice;
use App\Models\OltDevice;
use App\Models\ProvisioningAdapter;
use App\Services\Network\CtcomActivationService;
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
}
