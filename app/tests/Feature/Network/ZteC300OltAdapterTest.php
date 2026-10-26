<?php

namespace Tests\Feature\Network;

use App\Enums\PonMode;
use App\Models\OltDevice;
use App\Services\Network\OltSidecarClient;
use App\Services\Network\Provisioning\Exceptions\AdapterNotYetImplemented;
use App\Services\Network\Provisioning\Olt\ZteC300OltAdapter;
use App\Services\Network\Provisioning\OltAdapterRegistry;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.23.8.2b — ZTE C300 OLT adapter: membungkus activate_onu (OMCI) yang
 * proven v0.23.5. Nol sentuh device (OltSidecarClient di-mock).
 */
class ZteC300OltAdapterTest extends TestCase
{
    private function adapter(): ZteC300OltAdapter
    {
        $a = app(OltAdapterRegistry::class)->for('zte_c300');
        $this->assertInstanceOf(ZteC300OltAdapter::class, $a);

        return $a;
    }

    private function fullSpec(): array
    {
        return [
            'pon_interface' => '1/3/12',
            'onu_id' => 5,
            'sn' => 'ZTEGC1234567',
            'onu_type' => 'ZTE-F660',
            'name' => 'Budi - 262200112233',
            'tcont_profile' => 'HomeFixed-10Mbps',
            'traffic_profile' => 'PPPoE-Remote',
            'vlan_pppoe' => 10,
            'vlan_mgmt' => 9,
            'vlan_bridge' => 172,
            'requested_by' => 7,
        ];
    }

    public function test_pon_mode_is_gpon(): void
    {
        $this->assertSame(PonMode::Gpon, $this->adapter()->ponMode());
    }

    public function test_push_wan_internet_omci_delegates_to_activate_onu_with_mapped_params(): void
    {
        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->expects($this->once())->method('activateOnu')
            ->with(
                $this->isInstanceOf(OltDevice::class),
                $this->callback(function (array $params): bool {
                    // requested_by TIDAK ikut ke $params (dikirim sbg arg terpisah)
                    return ! array_key_exists('requested_by', $params)
                        && $params['pon_interface'] === '1/3/12'
                        && $params['onu_id'] === 5
                        && $params['vlan_pppoe'] === 10
                        && $params['name'] === 'Budi - 262200112233';
                }),
                7,
            )
            ->willReturn(['success' => true, 'data' => ['applied' => true]]);
        $this->app->instance(OltSidecarClient::class, $sidecar);

        $result = $this->adapter()->pushWanInternetOmci(new OltDevice, $this->fullSpec());

        $this->assertTrue($result['success']);
    }

    public function test_push_wan_internet_omci_throws_when_required_key_missing(): void
    {
        $spec = $this->fullSpec();
        unset($spec['vlan_bridge']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/vlan_bridge/');
        $this->adapter()->pushWanInternetOmci(new OltDevice, $spec);
    }

    public function test_apply_naming_is_documented_no_op_inline_in_activate_onu(): void
    {
        // Tidak boleh menyentuh sidecar sama sekali.
        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->expects($this->never())->method('activateOnu');
        $this->app->instance(OltSidecarClient::class, $sidecar);

        $result = $this->adapter()->applyNaming(new OltDevice, ['name' => 'X']);

        $this->assertTrue($result['named_inline']);
    }

    public function test_save_config_is_documented_no_op_auto_write(): void
    {
        $result = $this->adapter()->saveConfig(new OltDevice);

        $this->assertTrue($result['saved']);
        $this->assertTrue($result['auto_write']);
    }

    public function test_admit_throws_not_yet_implemented_with_allocation_note(): void
    {
        $this->expectException(AdapterNotYetImplemented::class);
        $this->expectExceptionMessageMatches('/onu-id|next-available|orkestrator/');
        $this->adapter()->admit(new OltDevice);
    }
}
