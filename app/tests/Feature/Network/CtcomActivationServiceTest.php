<?php

namespace Tests\Feature\Network;

use App\Models\Customer;
use App\Models\OltDevice;
use App\Models\ProvisioningWanInternet;
use App\Services\Network\CtcomActivationService;
use App\Services\Network\GenieAcsClientService;
use App\Services\Network\OltSidecarClient;
use App\Services\Network\RadcheckWriterService;
use App\Services\Network\RadiusCredentialService;
use App\Services\Network\TestCredentialSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.23.6 — scoped: SEMUA dependency di-mock (sidecar/GenieACS/radcheck/
 * deriveLiveWanParams); radacct pakai sqlite in-memory (pola
 * TestCredentialSyncServiceTest); Sleep::fake(). TIDAK pernah memanggil
 * OLT/GenieACS sungguhan. Customer in-memory (new Customer, tanpa DB).
 */
class CtcomActivationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.radius' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::purge('radius');
        DB::connection('radius')->statement(
            'CREATE TABLE radacct (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, acctstarttime TEXT, acctstoptime TEXT, framedipaddress TEXT, nasipaddress TEXT)'
        );
        DB::connection('radius')->statement(
            'CREATE TABLE radcheck (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, attribute TEXT, op TEXT, value TEXT)'
        );
        DB::connection('radius')->statement(
            'CREATE TABLE radreply (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, attribute TEXT, op TEXT, value TEXT)'
        );
        // Username yang dipakai deriveStub() — supaya guard radcheck (v0.23.8.6c)
        // lolos di test pushNetworkPolicy/activate yang memang mengharapkan sukses.
        $this->insertRadcheck('u@ppp.bajastu.id', 'wifijadipasti');
    }

    private function insertRadcheck(string $username, string $password): void
    {
        DB::connection('radius')->table('radcheck')->insert([
            'username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $password,
        ]);
    }

    private function seedActiveRadacct(string $username, string $ip = '10.0.1.139'): void
    {
        DB::connection('radius')->table('radacct')->insert([
            'username' => $username,
            'acctstarttime' => '2026-10-03 01:13:46',
            'acctstoptime' => null,
            'framedipaddress' => $ip,
            'nasipaddress' => '172.23.195.6',
        ]);
    }

    private function makeService(
        ?OltSidecarClient $sidecar = null,
        ?GenieAcsClientService $genieacs = null,
        ?RadcheckWriterService $radcheck = null,
        ?TestCredentialSyncService $credentials = null,
        ?RadiusCredentialService $radiusCredentials = null,
    ): CtcomActivationService {
        return new CtcomActivationService(
            $radcheck ?? $this->createMock(RadcheckWriterService::class),
            $credentials ?? $this->createMock(TestCredentialSyncService::class),
            $genieacs ?? $this->createMock(GenieAcsClientService::class),
            $sidecar ?? $this->createMock(OltSidecarClient::class),
            // Nyata (bukan mock): query ke koneksi radius sqlite in-memory test.
            $radiusCredentials ?? app(RadiusCredentialService::class),
        );
    }

    private function customer(string $name = 'Dahlia', string $cid = '2026104603'): Customer
    {
        $c = new Customer;
        $c->name = $name;
        $c->cid = $cid;

        return $c;
    }

    private function deriveStub(): TestCredentialSyncService
    {
        $credentials = $this->createMock(TestCredentialSyncService::class);
        $credentials->method('deriveLiveWanParams')->willReturn([
            'has_vlan_package' => true,
            'username' => 'u@ppp.bajastu.id',
            'password' => 'wifijadipasti',
            'framed_pool' => 'PPPOE-REMOTE',
            'vlan_pppoe' => 10,
        ]);

        return $credentials;
    }

    /** Device GenieACS: WAN internet VID10 di WCD.7 (bukan index 1), + bridge VID172 di WCD.6. */
    private function genieDeviceWithWan(bool $withBridge = true): array
    {
        $wcd = [
            '7' => [
                'WANPPPConnection' => ['1' => [
                    'Name' => ['_value' => '5_Other_R_VID_10'],
                    'ConnectionType' => ['_value' => 'IP_Routed'],
                ]],
                'X_CT-COM_WANGponLinkConfig' => ['VLANIDMark' => ['_value' => 10]],
            ],
        ];
        if ($withBridge) {
            $wcd['6'] = [
                'WANPPPConnection' => ['1' => [
                    'Name' => ['_value' => '4_INTERNET_B_VID_172'],
                    'ConnectionType' => ['_value' => 'PPPoE_Bridged'],
                ]],
                'X_CT-COM_WANGponLinkConfig' => ['VLANIDMark' => ['_value' => 172]],
            ];
        }

        return ['_id' => 'DEV', 'InternetGatewayDevice' => ['WANDevice' => ['1' => ['WANConnectionDevice' => $wcd]]]];
    }

    /**
     * Device EPON (E04ID): objek VLAN = X_CT-COM_WANEponLinkConfig (BUKAN Gpon)
     * — WAN internet VID131 di WCD.1, bridge VID172 di WCD.3. Membuktikan fix
     * v0.23.8.2 (deteksi GPON/EPON) melayani E04ID.
     */
    private function genieDeviceEpon(): array
    {
        $wcd = [
            '1' => [
                'WANPPPConnection' => ['2' => [
                    'Name' => ['_value' => '2_INTERNET_R_VID_131'],
                    'ConnectionType' => ['_value' => 'IP_Routed'],
                ]],
                'X_CT-COM_WANEponLinkConfig' => ['VLANIDMark' => ['_value' => 131]],
            ],
            '3' => [
                'WANPPPConnection' => ['1' => [
                    'Name' => ['_value' => '3_INTERNET_B_VID_172'],
                    'ConnectionType' => ['_value' => 'PPPoE_Bridged'],
                ]],
                'X_CT-COM_WANEponLinkConfig' => ['VLANIDMark' => ['_value' => 172]],
            ],
        ];

        return ['_id' => 'DEV', 'InternetGatewayDevice' => ['WANDevice' => ['1' => ['WANConnectionDevice' => $wcd]]]];
    }

    public function test_verify_bridge_present_true_via_epon_object(): void
    {
        $genieacs = $this->createMock(GenieAcsClientService::class);
        $genieacs->method('findDeviceById')->willReturn($this->genieDeviceEpon());

        $this->assertTrue($this->makeService(genieacs: $genieacs)->verifyBridgePresent('DEV', 172));
    }

    public function test_push_network_policy_resolves_vlan_via_epon_object(): void
    {
        $credentials = $this->createMock(TestCredentialSyncService::class);
        $credentials->method('deriveLiveWanParams')->willReturn([
            'has_vlan_package' => true,
            'username' => 'u@ppp.bajastu.id',
            'password' => 'wifijadipasti',
            'framed_pool' => 'PPPOE-REMOTE',
            'vlan_pppoe' => 131,
        ]);
        $genieacs = $this->createMock(GenieAcsClientService::class);
        $genieacs->method('findDeviceById')->willReturn($this->genieDeviceEpon());
        $genieacs->method('sendTask')->willReturn(['task_id' => 't1', 'connection_request_ok' => false]);

        $result = $this->makeService(genieacs: $genieacs, credentials: $credentials)
            ->pushNetworkPolicy($this->customer(), 'DEV');

        // Path WAN internet VID131 ter-resolve dari objek EPON (WCD.1 inst .2).
        $this->assertSame('InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.2', $result['wan_path']);
        $this->assertSame(131, $result['vlan']);
    }

    private function genieDevice(string $internetStatus): array
    {
        return [
            '_id' => 'DEV',
            'InternetGatewayDevice' => ['WANDevice' => ['1' => ['WANConnectionDevice' => [
                '5' => ['WANPPPConnection' => ['1' => [
                    'Name' => ['_value' => '3_INTERNET_R_VID_10'],
                    'ConnectionStatus' => ['_value' => $internetStatus],
                ]]],
            ]]]],
        ];
    }

    // ---- writeRadcheck ---------------------------------------------------

    public function test_write_radcheck_derives_and_writes(): void
    {
        $radcheck = $this->createMock(RadcheckWriterService::class);
        $radcheck->expects($this->once())->method('write')
            ->with('u@ppp.bajastu.id', 'wifijadipasti', 'PPPOE-REMOTE');

        $service = $this->makeService(radcheck: $radcheck, credentials: $this->deriveStub());
        $result = $service->writeRadcheck($this->customer());

        $this->assertSame('u@ppp.bajastu.id', $result['username']);
        $this->assertSame(10, $result['vlan']);
    }

    public function test_write_radcheck_throws_when_no_vlan_package(): void
    {
        $credentials = $this->createMock(TestCredentialSyncService::class);
        $credentials->method('deriveLiveWanParams')->willReturn([
            'has_vlan_package' => false,
            'message' => 'Pelanggan belum punya paket ber-VLAN.',
        ]);
        $radcheck = $this->createMock(RadcheckWriterService::class);
        $radcheck->expects($this->never())->method('write');

        $this->expectException(RuntimeException::class);
        $this->makeService(radcheck: $radcheck, credentials: $credentials)->writeRadcheck($this->customer());
    }

    // ---- pushNetworkPolicy ----------------------------------------------

    public function test_push_network_policy_resolves_vid10_dynamically_and_pushes_cred_nat_dhcp(): void
    {
        $genieacs = $this->createMock(GenieAcsClientService::class);
        $genieacs->method('findDeviceById')->willReturn($this->genieDeviceWithWan());
        $genieacs->expects($this->once())->method('sendTask')
            ->with('DEV', $this->callback(function (array $task): bool {
                if (($task['name'] ?? '') !== 'setParameterValues') {
                    return false;
                }
                $p = $task['parameterValues'];
                $base = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.7.WANPPPConnection.1';

                return $p[0] === ["{$base}.Username", 'u@ppp.bajastu.id', 'xsd:string']
                    && $p[1] === ["{$base}.Password", 'wifijadipasti', 'xsd:string']
                    && $p[2] === ["{$base}.NATEnabled", false, 'xsd:boolean']
                    && $p[3] === ['InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.DHCPServerEnable', false, 'xsd:boolean'];
            }), false)
            ->willReturn(['task_id' => 't1', 'connection_request_ok' => false]);

        $service = $this->makeService(genieacs: $genieacs, credentials: $this->deriveStub());
        $result = $service->pushNetworkPolicy($this->customer(), 'DEV');

        $this->assertSame('InternetGatewayDevice.WANDevice.1.WANConnectionDevice.7.WANPPPConnection.1', $result['wan_path']);
        $this->assertSame(10, $result['vlan']);
        $this->assertSame('t1', $result['task_id']);
    }

    public function test_push_network_policy_throws_when_vid10_wan_not_found(): void
    {
        // Device hanya punya bridge VID172, tak ada WAN routed VID10.
        $onlyBridge = ['_id' => 'DEV', 'InternetGatewayDevice' => ['WANDevice' => ['1' => ['WANConnectionDevice' => [
            '6' => ['WANPPPConnection' => ['1' => ['Name' => ['_value' => '4_INTERNET_B_VID_172'], 'ConnectionType' => ['_value' => 'PPPoE_Bridged']]], 'X_CT-COM_WANGponLinkConfig' => ['VLANIDMark' => ['_value' => 172]]],
        ]]]]];
        $genieacs = $this->createMock(GenieAcsClientService::class);
        $genieacs->method('findDeviceById')->willReturn($onlyBridge);
        $genieacs->expects($this->never())->method('sendTask');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('VLAN 10 tidak ditemukan');
        $this->makeService(genieacs: $genieacs, credentials: $this->deriveStub())->pushNetworkPolicy($this->customer(), 'DEV');
    }

    // ---- resolveWanCredentials (v0.23.8.6c, insiden 2026-10-25) ----------

    /**
     * Pelanggan termigrasi v0.8.4 (#57): username PPPoE = NOMOR TELEPON, sudah ada
     * di radcheck. Tanpa profil static → jalur (b) lookupForCustomer mengembalikan
     * username+password NYATA dari radcheck, BUKAN {cid}@ppp.bajastu.id.
     */
    public function test_resolve_wan_credentials_uses_real_radcheck_username_for_migrated_customer(): void
    {
        $this->insertRadcheck('085601070033', 'passreal');
        $c = $this->customer('Khikmatul', '245595852');
        $c->phone_number = '085601070033';

        $cred = $this->makeService(credentials: $this->deriveStub())->resolveWanCredentials($c);

        $this->assertSame('085601070033', $cred['username']);
        $this->assertSame('passreal', $cred['password']);
    }

    /** Pelanggan TANPA radcheck & tanpa writeRadcheck yang menulis → guard melempar, nol push. */
    public function test_resolve_wan_credentials_guard_throws_when_username_absent_from_radcheck(): void
    {
        $credentials = $this->createMock(TestCredentialSyncService::class);
        $credentials->method('deriveLiveWanParams')->willReturn([
            'has_vlan_package' => true,
            'username' => 'hantu@ppp.bajastu.id',
            'password' => 'x',
            'framed_pool' => 'PPPOE-REMOTE',
            'vlan_pppoe' => 10,
        ]);
        // writeRadcheck di-mock noop (TIDAK menulis baris) → username tetap absen.
        $radcheck = $this->createMock(RadcheckWriterService::class);
        $c = $this->customer('Tanpa', '999000');
        $c->phone_number = '081111'; // tak ada di radcheck

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak punya baris radcheck');
        $this->makeService(radcheck: $radcheck, credentials: $credentials)->resolveWanCredentials($c);
    }

    /** Pelanggan BARU: jalur (c) fallback {cid}@... — writeRadcheck menulis dulu, lalu guard lolos. */
    public function test_resolve_wan_credentials_falls_back_to_cid_after_write_radcheck_for_new_customer(): void
    {
        $credentials = $this->createMock(TestCredentialSyncService::class);
        $credentials->method('deriveLiveWanParams')->willReturn([
            'has_vlan_package' => true,
            'username' => '777001@ppp.bajastu.id',
            'password' => 'wifijadipasti',
            'framed_pool' => 'PPPOE-REMOTE',
            'vlan_pppoe' => 10,
        ]);
        // writeRadcheck NYATA menulis baris (simulasi efek RadcheckWriterService).
        $radcheck = $this->createMock(RadcheckWriterService::class);
        $radcheck->method('write')->willReturnCallback(function (string $u, string $p): void {
            $this->insertRadcheck($u, $p);
        });
        $c = $this->customer('Baru', '777001'); // tanpa phone/legacy → kandidat = 777001@ppp.bajastu.id

        $cred = $this->makeService(radcheck: $radcheck, credentials: $credentials)->resolveWanCredentials($c);

        $this->assertSame('777001@ppp.bajastu.id', $cred['username']);
        $this->assertSame('wifijadipasti', $cred['password']);
    }

    /** Profil strategi static: username DAN password dari profil (bukan dari radcheck). */
    public function test_resolve_wan_credentials_uses_static_profile_username_and_password(): void
    {
        // radcheck punya password BEDA utk username yg sama → buktikan password
        // diambil dari profil static, bukan radcheck.
        $this->insertRadcheck('085601070033', 'passradcheck');
        $profile = new ProvisioningWanInternet([
            'pppoe_username_strategy' => 'static',
            'pppoe_username_static' => '085601070033',
            'pppoe_password_strategy' => 'static',
            'pppoe_password_static' => 'passprofile',
        ]);

        $cred = $this->makeService()->resolveWanCredentials($this->customer(), $profile);

        $this->assertSame('085601070033', $cred['username']);
        $this->assertSame('passprofile', $cred['password']);
    }

    // ---- verifyBridgePresent --------------------------------------------

    public function test_verify_bridge_present_true_when_vid172_exists(): void
    {
        $genieacs = $this->createMock(GenieAcsClientService::class);
        $genieacs->method('findDeviceById')->willReturn($this->genieDeviceWithWan(withBridge: true));
        $this->assertTrue($this->makeService(genieacs: $genieacs)->verifyBridgePresent('DEV'));
    }

    public function test_verify_bridge_present_false_when_no_vid172(): void
    {
        $genieacs = $this->createMock(GenieAcsClientService::class);
        $genieacs->method('findDeviceById')->willReturn($this->genieDeviceWithWan(withBridge: false));
        $this->assertFalse($this->makeService(genieacs: $genieacs)->verifyBridgePresent('DEV'));
    }

    // ---- pollRadacctConnected -------------------------------------------

    public function test_poll_radacct_connected_when_active_session_exists(): void
    {
        Sleep::fake();
        $this->seedActiveRadacct('u@ppp.bajastu.id', '10.0.1.139');

        $r = $this->makeService()->pollRadacctConnected('u@ppp.bajastu.id', 300, 30);

        $this->assertTrue($r['connected']);
        $this->assertSame('10.0.1.139', $r['framed_ip']);
        $this->assertSame(0, $r['elapsed_seconds']);
        Sleep::assertNeverSlept();
    }

    public function test_poll_radacct_times_out_when_no_active_session(): void
    {
        Sleep::fake();
        $r = $this->makeService()->pollRadacctConnected('u@ppp.bajastu.id', 60, 30);

        $this->assertFalse($r['connected']);
        $this->assertSame(60, $r['elapsed_seconds']);
        $this->assertStringContainsString('radacct aktif', $r['reason']);
    }

    // ---- applyOmciNaming -------------------------------------------------

    public function test_apply_omci_naming_resolves_and_sets_name(): void
    {
        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('resolveOnuBySn')->willReturn([
            'success' => true,
            'data' => ['found' => true, 'pon' => 1, 'onu_id' => 28],
        ]);
        $sidecar->expects($this->once())->method('setOntNaming')
            ->with($this->isInstanceOf(OltDevice::class), $this->callback(fn (array $p): bool => $p['pon'] === 1 && $p['onu_id'] === 28 && $p['name'] === 'Dahlia - 2026104603'))
            ->willReturn(['success' => true, 'data' => []]);

        $result = $this->makeService(sidecar: $sidecar)->applyOmciNaming(new OltDevice, $this->customer(), 'CMDCA200BB76');
        $this->assertSame('Dahlia - 2026104603', $result['name']);
    }

    public function test_apply_omci_naming_throws_when_sn_not_found(): void
    {
        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('resolveOnuBySn')->willReturn(['success' => true, 'data' => ['found' => false]]);
        $sidecar->expects($this->never())->method('setOntNaming');

        $this->expectException(RuntimeException::class);
        $this->makeService(sidecar: $sidecar)->applyOmciNaming(new OltDevice, $this->customer(), 'CMDCA200BB76');
    }

    public function test_apply_omci_naming_throws_when_device_rejects_set(): void
    {
        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('resolveOnuBySn')->willReturn(['success' => true, 'data' => ['found' => true, 'pon' => 2, 'onu_id' => 5]]);
        $sidecar->method('setOntNaming')->willReturn(['success' => false, 'device_message' => '% error']);

        $this->expectException(RuntimeException::class);
        $this->makeService(sidecar: $sidecar)->applyOmciNaming(new OltDevice, $this->customer(), 'CMDCA200BB76');
    }

    public function test_build_ont_name_truncates_to_40(): void
    {
        $name = $this->makeService()->buildOntName($this->customer('Nama Pelanggan Sangat Panjang Sekali Benar', '2553'));
        $this->assertLessThanOrEqual(40, mb_strlen($name));
    }

    // ---- pollWanConnected (GenieACS, dipertahankan — tak dipakai activate lagi) ----

    public function test_poll_wan_connected_immediately_when_internet_connected(): void
    {
        Sleep::fake();
        $genieacs = $this->createMock(GenieAcsClientService::class);
        $genieacs->method('queryDevices')->willReturn([$this->genieDevice('Connected')]);

        $r = $this->makeService(genieacs: $genieacs)->pollWanConnected('CMDCA200BB76', 300, 30);
        $this->assertTrue($r['connected']);
        Sleep::assertNeverSlept();
    }

    // ---- activate() orchestrator ----------------------------------------

    public function test_activate_happy_path_push_then_radacct_then_name(): void
    {
        Sleep::fake();
        $this->seedActiveRadacct('u@ppp.bajastu.id');

        $radcheck = $this->createMock(RadcheckWriterService::class);
        $radcheck->expects($this->once())->method('write');

        $genieacs = $this->createMock(GenieAcsClientService::class);
        $genieacs->method('findDeviceById')->willReturn($this->genieDeviceWithWan());
        $genieacs->expects($this->once())->method('sendTask')->willReturn(['task_id' => 't1', 'connection_request_ok' => false]);

        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('resolveOnuBySn')->willReturn(['success' => true, 'data' => ['found' => true, 'pon' => 1, 'onu_id' => 28]]);
        $sidecar->expects($this->once())->method('setOntNaming')->willReturn(['success' => true, 'data' => []]);
        $sidecar->expects($this->once())->method('saveConfig')->willReturn(['success' => true, 'data' => ['saved' => true, 'raw_excerpt' => 'Configuration saved successfully']]);

        $service = $this->makeService($sidecar, $genieacs, $radcheck, $this->deriveStub());
        $result = $service->activate(new OltDevice, $this->customer(), 'CMDCA200BB76', 'DEV');

        $this->assertTrue($result['ok']);
        $this->assertSame('done', $result['stage']);
        $this->assertTrue($result['bridge_present']);
        $this->assertSame('Dahlia - 2026104603', $result['naming']['name']);
        $this->assertTrue($result['save']['saved']);
    }

    public function test_save_config_persists_and_throws_on_failure(): void
    {
        $sidecarOk = $this->createMock(OltSidecarClient::class);
        $sidecarOk->method('saveConfig')->willReturn(['success' => true, 'data' => ['saved' => true, 'raw_excerpt' => 'Configuration saved successfully']]);
        $this->assertTrue($this->makeService(sidecar: $sidecarOk)->saveConfig(new OltDevice)['saved']);

        $sidecarFail = $this->createMock(OltSidecarClient::class);
        $sidecarFail->method('saveConfig')->willReturn(['success' => false, 'device_message' => '% error']);
        $this->expectException(RuntimeException::class);
        $this->makeService(sidecar: $sidecarFail)->saveConfig(new OltDevice);
    }

    public function test_activate_stops_at_radacct_poll_and_never_names(): void
    {
        Sleep::fake();
        // TIDAK seed radacct -> poll timeout.

        $genieacs = $this->createMock(GenieAcsClientService::class);
        $genieacs->method('findDeviceById')->willReturn($this->genieDeviceWithWan());
        $genieacs->method('sendTask')->willReturn(['task_id' => 't1', 'connection_request_ok' => false]);

        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->expects($this->never())->method('resolveOnuBySn');
        $sidecar->expects($this->never())->method('setOntNaming');

        $service = $this->makeService(
            sidecar: $sidecar, genieacs: $genieacs,
            radcheck: $this->createMock(RadcheckWriterService::class), credentials: $this->deriveStub(),
        );
        $result = $service->activate(new OltDevice, $this->customer(), 'CMDCA200BB76', 'DEV');

        $this->assertFalse($result['ok']);
        $this->assertSame('poll_radacct', $result['stage']);
        $this->assertNull($result['naming']);
    }
}
