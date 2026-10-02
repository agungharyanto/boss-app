<?php

namespace Tests\Feature\Network;

use App\Models\Customer;
use App\Models\OltDevice;
use App\Services\Network\G02idActivationService;
use App\Services\Network\GenieAcsClientService;
use App\Services\Network\OltSidecarClient;
use App\Services\Network\RadcheckWriterService;
use App\Services\Network\TestCredentialSyncService;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.23.6 — scoped: SEMUA dependency di-mock (sidecar/GenieACS/radcheck/
 * deriveLiveWanParams), Sleep::fake(). TIDAK pernah memanggil OLT/GenieACS
 * sungguhan. Customer dibuat in-memory (new Customer, tanpa DB).
 */
class G02idActivationServiceTest extends TestCase
{
    private function makeService(
        ?OltSidecarClient $sidecar = null,
        ?GenieAcsClientService $genieacs = null,
        ?RadcheckWriterService $radcheck = null,
        ?TestCredentialSyncService $credentials = null,
    ): G02idActivationService {
        return new G02idActivationService(
            $radcheck ?? $this->createMock(RadcheckWriterService::class),
            $credentials ?? $this->createMock(TestCredentialSyncService::class),
            $genieacs ?? $this->createMock(GenieAcsClientService::class),
            $sidecar ?? $this->createMock(OltSidecarClient::class),
        );
    }

    private function customer(string $name = 'Dahlia', string $cid = '255324578770'): Customer
    {
        $c = new Customer;
        $c->name = $name;
        $c->cid = $cid;

        return $c;
    }

    /** Device GenieACS tiruan dengan WAN internet pada ConnectionStatus tertentu. */
    private function genieDevice(string $internetStatus): array
    {
        return [
            '_id' => '5C75C6-H3-2S XPON-CMDCA200BB76',
            '_deviceId' => ['_SerialNumber' => 'CMDCA200BB76'],
            'InternetGatewayDevice' => ['WANDevice' => ['1' => ['WANConnectionDevice' => [
                '3' => ['WANPPPConnection' => ['1' => [
                    'Name' => ['_value' => '2_Other_B_VID_172'],
                    'ConnectionStatus' => ['_value' => 'Connected'],
                ]]],
                '5' => ['WANPPPConnection' => ['1' => [
                    'Name' => ['_value' => '3_INTERNET_R_VID_10'],
                    'ConnectionStatus' => ['_value' => $internetStatus],
                ]]],
            ]]]],
        ];
    }

    // ---- 3a writeRadcheck ------------------------------------------------

    public function test_write_radcheck_derives_and_writes(): void
    {
        $credentials = $this->createMock(TestCredentialSyncService::class);
        $credentials->method('deriveLiveWanParams')->willReturn([
            'has_vlan_package' => true,
            'username' => '255324578770@ppp.bajastu.id',
            'password' => 'wifijadipasti',
            'framed_pool' => 'PPPOE-REMOTE',
            'vlan_pppoe' => 10,
        ]);

        $radcheck = $this->createMock(RadcheckWriterService::class);
        $radcheck->expects($this->once())->method('write')
            ->with('255324578770@ppp.bajastu.id', 'wifijadipasti', 'PPPOE-REMOTE');

        $service = $this->makeService(radcheck: $radcheck, credentials: $credentials);
        $result = $service->writeRadcheck($this->customer());

        $this->assertSame('255324578770@ppp.bajastu.id', $result['username']);
        $this->assertSame(10, $result['vlan']);
        $this->assertSame('PPPOE-REMOTE', $result['framed_pool']);
    }

    public function test_write_radcheck_throws_and_does_not_write_when_no_vlan_package(): void
    {
        $credentials = $this->createMock(TestCredentialSyncService::class);
        $credentials->method('deriveLiveWanParams')->willReturn([
            'has_vlan_package' => false,
            'message' => 'Pelanggan belum punya paket ber-VLAN.',
        ]);

        $radcheck = $this->createMock(RadcheckWriterService::class);
        $radcheck->expects($this->never())->method('write');

        $service = $this->makeService(radcheck: $radcheck, credentials: $credentials);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum punya paket ber-VLAN');
        $service->writeRadcheck($this->customer());
    }

    // ---- 3b pollWanConnected --------------------------------------------

    public function test_poll_returns_connected_immediately_when_internet_wan_connected(): void
    {
        Sleep::fake();
        $genieacs = $this->createMock(GenieAcsClientService::class);
        $genieacs->method('queryDevices')->willReturn([$this->genieDevice('Connected')]);

        $service = $this->makeService(genieacs: $genieacs);
        $result = $service->pollWanConnected('CMDCA200BB76', maxSeconds: 300, intervalSeconds: 30);

        $this->assertTrue($result['connected']);
        $this->assertSame('Connected', $result['wan_status']);
        $this->assertSame(0, $result['elapsed_seconds']);
        Sleep::assertNeverSlept();
    }

    public function test_poll_times_out_when_internet_wan_stays_connecting(): void
    {
        Sleep::fake();
        $genieacs = $this->createMock(GenieAcsClientService::class);
        $genieacs->method('queryDevices')->willReturn([$this->genieDevice('Connecting')]);

        $service = $this->makeService(genieacs: $genieacs);
        $result = $service->pollWanConnected('CMDCA200BB76', maxSeconds: 60, intervalSeconds: 30);

        $this->assertFalse($result['connected']);
        $this->assertSame('Connecting', $result['wan_status']);
        $this->assertSame(60, $result['elapsed_seconds']);
        $this->assertStringContainsString('belum Connected', $result['reason']);
        Sleep::assertSleptTimes(2); // t=0 cek, sleep, t=30 cek, sleep, t=60 cek -> timeout
    }

    public function test_poll_times_out_when_device_never_informs(): void
    {
        Sleep::fake();
        $genieacs = $this->createMock(GenieAcsClientService::class);
        $genieacs->method('queryDevices')->willReturn([]); // 0 match selalu

        $service = $this->makeService(genieacs: $genieacs);
        $result = $service->pollWanConnected('CMDCA200BB76', maxSeconds: 60, intervalSeconds: 30);

        $this->assertFalse($result['connected']);
        $this->assertNull($result['device_id']);
        $this->assertStringContainsString('belum pernah Inform', $result['reason']);
    }

    // ---- 3c applyOmciNaming ---------------------------------------------

    public function test_apply_omci_naming_resolves_and_sets_name(): void
    {
        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('resolveOnuBySn')->willReturn([
            'success' => true,
            'data' => ['found' => true, 'pon' => 1, 'onu_id' => 28, 'state' => 'Active', 'run_state' => 'Online'],
        ]);
        $sidecar->expects($this->once())->method('setOntNaming')
            ->with($this->isInstanceOf(OltDevice::class), $this->callback(function (array $params): bool {
                return $params['pon'] === 1
                    && $params['onu_id'] === 28
                    && $params['name'] === 'Dahlia - 255324578770';
            }))
            ->willReturn(['success' => true, 'data' => ['pon' => 1, 'onu_id' => 28]]);

        $service = $this->makeService(sidecar: $sidecar);
        $result = $service->applyOmciNaming(new OltDevice, $this->customer(), 'CMDCA200BB76');

        $this->assertSame(1, $result['pon']);
        $this->assertSame(28, $result['onu_id']);
        $this->assertSame('Dahlia - 255324578770', $result['name']);
    }

    public function test_apply_omci_naming_throws_when_sn_not_found_and_does_not_set(): void
    {
        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('resolveOnuBySn')->willReturn([
            'success' => true,
            'data' => ['found' => false, 'pon' => null, 'onu_id' => null],
        ]);
        $sidecar->expects($this->never())->method('setOntNaming');

        $service = $this->makeService(sidecar: $sidecar);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak ditemukan di OLT');
        $service->applyOmciNaming(new OltDevice, $this->customer(), 'CMDCA200BB76');
    }

    public function test_apply_omci_naming_throws_when_device_rejects_set(): void
    {
        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('resolveOnuBySn')->willReturn([
            'success' => true,
            'data' => ['found' => true, 'pon' => 2, 'onu_id' => 5],
        ]);
        $sidecar->method('setOntNaming')->willReturn([
            'success' => false,
            'device_message' => "Device menolak command 'ont setting 5 name ...': % error",
        ]);

        $service = $this->makeService(sidecar: $sidecar);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set nama OMCI gagal');
        $service->applyOmciNaming(new OltDevice, $this->customer(), 'CMDCA200BB76');
    }

    // ---- buildOntName + orchestrator ------------------------------------

    public function test_build_ont_name_truncates_to_40(): void
    {
        $service = $this->makeService();
        $name = $service->buildOntName($this->customer('Nama Pelanggan Sangat Panjang Sekali Benar', '2553'));

        $this->assertLessThanOrEqual(40, mb_strlen($name));
    }

    public function test_activate_happy_path_chains_all_three(): void
    {
        Sleep::fake();

        $credentials = $this->createMock(TestCredentialSyncService::class);
        $credentials->method('deriveLiveWanParams')->willReturn([
            'has_vlan_package' => true, 'username' => 'u@ppp.bajastu.id',
            'password' => 'wifijadipasti', 'framed_pool' => 'PPPOE-REMOTE', 'vlan_pppoe' => 10,
        ]);
        $radcheck = $this->createMock(RadcheckWriterService::class);
        $radcheck->expects($this->once())->method('write');

        $genieacs = $this->createMock(GenieAcsClientService::class);
        $genieacs->method('queryDevices')->willReturn([$this->genieDevice('Connected')]);

        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('resolveOnuBySn')->willReturn([
            'success' => true, 'data' => ['found' => true, 'pon' => 1, 'onu_id' => 28],
        ]);
        $sidecar->expects($this->once())->method('setOntNaming')
            ->willReturn(['success' => true, 'data' => []]);

        $service = $this->makeService($sidecar, $genieacs, $radcheck, $credentials);
        $result = $service->activate(new OltDevice, $this->customer(), 'CMDCA200BB76');

        $this->assertTrue($result['ok']);
        $this->assertSame('done', $result['stage']);
        $this->assertSame('Dahlia - 255324578770', $result['naming']['name']);
    }

    public function test_activate_stops_at_poll_and_never_names_when_not_connected(): void
    {
        Sleep::fake();

        $credentials = $this->createMock(TestCredentialSyncService::class);
        $credentials->method('deriveLiveWanParams')->willReturn([
            'has_vlan_package' => true, 'username' => 'u@ppp.bajastu.id',
            'password' => 'wifijadipasti', 'framed_pool' => 'PPPOE-REMOTE', 'vlan_pppoe' => 10,
        ]);
        $genieacs = $this->createMock(GenieAcsClientService::class);
        $genieacs->method('queryDevices')->willReturn([$this->genieDevice('Connecting')]);

        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->expects($this->never())->method('resolveOnuBySn');
        $sidecar->expects($this->never())->method('setOntNaming');

        $service = $this->makeService(
            sidecar: $sidecar, genieacs: $genieacs,
            radcheck: $this->createMock(RadcheckWriterService::class), credentials: $credentials,
        );
        $result = $service->activate(new OltDevice, $this->customer(), 'CMDCA200BB76', requestedBy: null);

        $this->assertFalse($result['ok']);
        $this->assertSame('poll_wan', $result['stage']);
        $this->assertNull($result['naming']);
    }
}
