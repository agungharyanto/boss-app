<?php

namespace Tests\Feature\Network;

use App\Models\Nas;
use App\Models\OltDevice;
use App\Models\OltUplinkPort;
use App\Services\Network\OltSidecarClient;
use App\Services\Network\OltUplinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.23.5 Bagian 2 — refresh uplink. OltSidecarClient di-mock penuh
 * (tidak pernah menyentuh OLT sungguhan).
 */
class OltUplinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private function olt(): OltDevice
    {
        // OltDeviceFactory gotcha: tenant_id closure baca nas_id sebelum
        // resolve — kirim nas_id konkret (lihat CLAUDE.md).
        $nas = Nas::factory()->create();

        return OltDevice::factory()->create(['nas_id' => $nas->id]);
    }

    private function portPayload(string $name, string $tagged): array
    {
        return [
            'name' => $name,
            'is_10g' => str_starts_with($name, 'xgei_'),
            'port_status_lines' => ["{$name} is up,  line protocol is up,  detect status is OK", 'The port is optical'],
            'optical_lines' => ['Wavelength     : 1310      (nm)', 'RxPower        : -5.0'],
            'vlan_lines' => ['hybrid>=0     1     0', 'TaggedVlan:', $tagged],
        ];
    }

    public function test_refresh_parses_and_upserts_ports(): void
    {
        $olt = $this->olt();
        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('read')->willReturn([
            'success' => true,
            'data' => ['ports' => [
                $this->portPayload('gei_1/19/4', '9-10,172'),
                $this->portPayload('xgei_1/19/1', '101'),
            ]],
        ]);
        $this->app->instance(OltSidecarClient::class, $sidecar);

        $result = app(OltUplinkService::class)->refresh($olt);

        $this->assertCount(2, $result);
        $this->assertDatabaseHas('olt_uplink_ports', [
            'olt_device_id' => $olt->id, 'port_name' => 'gei_1/19/4',
            'admin_state' => 'up', 'tagged_vlans' => '9-10,172', 'wavelength_nm' => 1310,
        ]);
        $this->assertDatabaseHas('olt_uplink_ports', [
            'olt_device_id' => $olt->id, 'port_name' => 'xgei_1/19/1', 'is_10g' => true,
        ]);
    }

    public function test_refresh_prunes_ports_no_longer_reported(): void
    {
        $olt = $this->olt();
        OltUplinkPort::create(['olt_device_id' => $olt->id, 'port_name' => 'gei_1/99/9']); // basi

        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('read')->willReturn([
            'success' => true,
            'data' => ['ports' => [$this->portPayload('gei_1/19/4', '9')]],
        ]);
        $this->app->instance(OltSidecarClient::class, $sidecar);

        app(OltUplinkService::class)->refresh($olt);

        $this->assertDatabaseMissing('olt_uplink_ports', ['olt_device_id' => $olt->id, 'port_name' => 'gei_1/99/9']);
        $this->assertDatabaseHas('olt_uplink_ports', ['olt_device_id' => $olt->id, 'port_name' => 'gei_1/19/4']);
    }

    public function test_refresh_throws_when_sidecar_fails(): void
    {
        $olt = $this->olt();
        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('read')->willReturn(['success' => false, 'device_message' => 'OLT tak terjangkau']);
        $this->app->instance(OltSidecarClient::class, $sidecar);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OLT tak terjangkau');

        app(OltUplinkService::class)->refresh($olt);
    }

    public function test_cached_reads_without_touching_sidecar(): void
    {
        $olt = $this->olt();
        OltUplinkPort::create(['olt_device_id' => $olt->id, 'port_name' => 'gei_1/19/4']);

        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->expects($this->never())->method('read');
        $this->app->instance(OltSidecarClient::class, $sidecar);

        $this->assertCount(1, app(OltUplinkService::class)->cached($olt));
    }
}
