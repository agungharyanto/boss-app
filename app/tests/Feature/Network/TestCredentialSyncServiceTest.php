<?php

namespace Tests\Feature\Network;

use App\Enums\NetworkProfileGroupType;
use App\Models\Customer;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\OltDevice;
use App\Models\PppPackage;
use App\Models\TestOnuWanConfig;
use App\Services\Network\OltSidecarClient;
use App\Services\Network\TestCredentialSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.23.5 Bagian D — TIDAK PERNAH memanggil OLT sungguhan (OltSidecarClient
 * di-mock penuh). Verifikasi orkestrasi (urutan langkah + STOP di setiap
 * kegagalan) dan guard is_test_fixture, bukan perilaku sidecar itu sendiri
 * (sudah dicover OltSidecarClientTest).
 */
class TestCredentialSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sleep::fake() — pollForWorkingState() memakai Sleep::for(...)
        // (bukan sleep() biasa) supaya test ini tidak benar-benar
        // menunggu WORKING_POLL_DELAY_SECONDS x WORKING_POLL_ATTEMPTS
        // detik. Pola sama seperti CpeSignalHistoryServiceTest.
        Sleep::fake();

        config(['database.connections.radius' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('radius');

        DB::connection('radius')->statement('
            CREATE TABLE radcheck (id INTEGER PRIMARY KEY, username TEXT, attribute TEXT, op TEXT, value TEXT)
        ');
        DB::connection('radius')->statement('
            CREATE TABLE radreply (id INTEGER PRIMARY KEY, username TEXT, attribute TEXT, op TEXT, value TEXT)
        ');
    }

    private function makeTestCustomer(bool $isTestFixture = true, ?array $metadata = null): Customer
    {
        if ($metadata === null) {
            // OltDeviceFactory's tenant_id closure reads $attributes['nas_id']
            // BEFORE that key is resolved unless a concrete nas_id is passed
            // in explicitly (known factory-attribute-order gotcha — see
            // CLAUDE.md).
            $nas = Nas::factory()->create();
            $olt = OltDevice::factory()->create(['nas_id' => $nas->id]);
            $metadata = [
                'olt_device_id' => $olt->id,
                'pon_interface' => '1/3/12',
                'onu_id' => 4,
                'sn' => 'CMDCA45762D6',
                'onu_type' => 'M12X5G_XPON',
                'vlan_mgmt' => 9,
                'vlan_bridge' => 172,
                'tcont_profile' => 'HomeFixed-10Mbps',
                'traffic_profile' => 'PPPoE-Remote',
            ];
        }

        return Customer::factory()->create([
            'is_test_fixture' => $isTestFixture,
            'test_onu_metadata' => $metadata,
        ]);
    }

    private function makeGroupWithPool(string $interfaceName, string $poolName): NetworkProfileGroup
    {
        $nas = Nas::factory()->create();
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => $poolName]);

        return NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id,
            'customer_ip_pool_id' => $pool->id,
            'interface_name' => $interfaceName,
        ]);
    }

    public function test_rejects_a_customer_that_is_not_a_test_fixture(): void
    {
        $customer = $this->makeTestCustomer(isTestFixture: false);
        $group = $this->makeGroupWithPool('vlan10-PPPoE', 'PPPOE-REMOTE');
        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->expects($this->never())->method('deleteOnu');

        $this->app->instance(OltSidecarClient::class, $sidecar);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is_test_fixture=true');

        app(TestCredentialSyncService::class)->updatePackage($customer, $group->id, 'newpass');
    }

    public function test_rejects_a_test_fixture_customer_with_no_onu_metadata(): void
    {
        $customer = $this->makeTestCustomer(metadata: []);
        $group = $this->makeGroupWithPool('vlan10-PPPoE', 'PPPOE-REMOTE');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test_onu_metadata');

        app(TestCredentialSyncService::class)->updatePackage($customer, $group->id, 'newpass');
    }

    public function test_stops_immediately_when_delete_onu_fails_and_never_calls_activate(): void
    {
        $customer = $this->makeTestCustomer();
        $group = $this->makeGroupWithPool('vlan10-PPPoE', 'PPPOE-REMOTE');

        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->expects($this->once())->method('deleteOnu')
            ->willReturn(['success' => false, 'device_message' => 'Device menolak command']);
        $sidecar->expects($this->never())->method('activateOnu');
        $sidecar->expects($this->never())->method('addPppoe');
        $this->app->instance(OltSidecarClient::class, $sidecar);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('delete_onu gagal');

        app(TestCredentialSyncService::class)->updatePackage($customer, $group->id, 'newpass');
    }

    public function test_stops_when_onu_still_appears_in_state_after_delete(): void
    {
        $customer = $this->makeTestCustomer();
        $group = $this->makeGroupWithPool('vlan10-PPPoE', 'PPPOE-REMOTE');

        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('deleteOnu')->willReturn(['success' => true, 'data' => ['commands_applied' => 4]]);
        $sidecar->method('read')->willReturn([
            'success' => true,
            'data' => ['lines' => ['1/3/12:4    enable       enable      working      1(GPON)']],
        ]);
        $sidecar->expects($this->never())->method('activateOnu');
        $this->app->instance(OltSidecarClient::class, $sidecar);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak bersih');

        app(TestCredentialSyncService::class)->updatePackage($customer, $group->id, 'newpass');
    }

    public function test_stops_when_activate_onu_never_reaches_working_state(): void
    {
        $customer = $this->makeTestCustomer();
        $group = $this->makeGroupWithPool('vlan10-PPPoE', 'PPPOE-REMOTE');

        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('deleteOnu')->willReturn(['success' => true, 'data' => ['commands_applied' => 4]]);
        $sidecar->method('activateOnu')->willReturn(['success' => true, 'data' => ['commands_applied' => 27]]);
        // Kedua panggilan read() (verifikasi bersih + verifikasi online) sama-sama "kosong" —
        // benar untuk verifikasi bersih, tapi salah (tidak working) untuk verifikasi online.
        $sidecar->method('read')->willReturn(['success' => true, 'data' => ['lines' => []]]);
        $sidecar->expects($this->never())->method('addPppoe');
        $this->app->instance(OltSidecarClient::class, $sidecar);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("tidak menunjukkan status 'working'");

        app(TestCredentialSyncService::class)->updatePackage($customer, $group->id, 'newpass');
    }

    public function test_full_success_writes_radcheck_and_updates_customer_metadata(): void
    {
        $customer = $this->makeTestCustomer();
        $group = $this->makeGroupWithPool('vlan10-PPPoE', 'PPPOE-REMOTE');

        $callOrder = [];
        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('deleteOnu')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'delete';

            return ['success' => true, 'data' => ['commands_applied' => 4]];
        });
        $sidecar->method('read')->willReturnCallback(function ($olt, $operation) use (&$callOrder) {
            $callOrder[] = 'read';
            // Kosong untuk verifikasi bersih (panggilan pertama), "working" untuk verifikasi online (kedua).
            $readCount = count(array_filter($callOrder, fn ($c) => $c === 'read'));

            return $readCount === 1
                ? ['success' => true, 'data' => ['lines' => []]]
                : ['success' => true, 'data' => ['lines' => ['1/3/12:4    enable       enable      working      1(GPON)']]];
        });
        $sidecar->method('activateOnu')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'activate';

            return ['success' => true, 'data' => ['commands_applied' => 27]];
        });
        $sidecar->method('addPppoe')->willReturnCallback(function ($olt, $params) use (&$callOrder) {
            $callOrder[] = 'add_pppoe';
            $this->assertSame('newpass', $params['password']);

            return ['success' => true, 'data' => ['commands_applied' => 4]];
        });
        $this->app->instance(OltSidecarClient::class, $sidecar);

        $result = app(TestCredentialSyncService::class)->updatePackage($customer, $group->id, 'newpass');

        $this->assertSame(['delete', 'read', 'activate', 'read', 'add_pppoe'], $callOrder);
        $this->assertSame(10, $result['vlan_pppoe']);
        $this->assertSame('PPPOE-REMOTE', $result['framed_pool']);

        $username = "{$customer->cid}@ppp.bajastu.id";
        $check = DB::connection('radius')->table('radcheck')->where('username', $username)->first();
        $this->assertSame('newpass', $check->value);
        $reply = DB::connection('radius')->table('radreply')
            ->where('username', $username)->where('attribute', 'Framed-Pool')->first();
        $this->assertSame('PPPOE-REMOTE', $reply->value);

        $this->assertSame(10, $customer->fresh()->test_onu_metadata['current_vlan_pppoe']);
    }

    public function test_rejects_a_test_fixture_customer_with_no_tcont_or_traffic_profile_in_metadata(): void
    {
        $customer = $this->makeTestCustomer(metadata: [
            'olt_device_id' => OltDevice::factory()->create(['nas_id' => Nas::factory()->create()->id])->id,
            'pon_interface' => '1/3/12',
            'onu_id' => 4,
            'sn' => 'CMDCA45762D6',
            // tcont_profile / traffic_profile SENGAJA tidak diisi.
        ]);
        $group = $this->makeGroupWithPool('vlan10-PPPoE', 'PPPOE-REMOTE');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tcont_profile/traffic_profile');

        app(TestCredentialSyncService::class)->updatePackage($customer, $group->id, 'newpass');
    }

    public function test_activate_onu_receives_tcont_and_traffic_profile_from_metadata_not_hardcoded(): void
    {
        $customer = $this->makeTestCustomer(metadata: [
            'olt_device_id' => OltDevice::factory()->create(['nas_id' => Nas::factory()->create()->id])->id,
            'pon_interface' => '1/3/12',
            'onu_id' => 4,
            'sn' => 'CMDCA45762D6',
            'tcont_profile' => 'CustomTcontProfile',
            'traffic_profile' => 'CustomTrafficProfile',
        ]);
        $group = $this->makeGroupWithPool('vlan10-PPPoE', 'PPPOE-REMOTE');

        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('deleteOnu')->willReturn(['success' => true, 'data' => []]);
        $sidecar->method('activateOnu')->willReturnCallback(function ($olt, $params) {
            $this->assertSame('CustomTcontProfile', $params['tcont_profile']);
            $this->assertSame('CustomTrafficProfile', $params['traffic_profile']);

            return ['success' => true, 'data' => []];
        });
        $sidecar->method('read')->willReturnOnConsecutiveCalls(
            ['success' => true, 'data' => ['lines' => []]],
            ['success' => true, 'data' => ['lines' => ['1/3/12:4 enable enable working 1(GPON)']]],
        );
        $sidecar->method('addPppoe')->willReturn(['success' => true, 'data' => []]);
        $this->app->instance(OltSidecarClient::class, $sidecar);

        app(TestCredentialSyncService::class)->updatePackage($customer, $group->id, 'newpass');
    }

    public function test_polls_for_working_state_and_succeeds_once_it_appears_on_a_later_attempt(): void
    {
        $customer = $this->makeTestCustomer();
        $group = $this->makeGroupWithPool('vlan10-PPPoE', 'PPPOE-REMOTE');

        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('deleteOnu')->willReturn(['success' => true, 'data' => []]);
        $sidecar->method('activateOnu')->willReturn(['success' => true, 'data' => []]);
        // Panggilan read() pertama = verifikasi bersih (harus kosong).
        // 3 panggilan read() berikutnya = poll status: kosong, kosong,
        // baru working di percobaan ke-3 — membuktikan retry benar-benar
        // dipakai, bukan langsung throw di percobaan pertama.
        $sidecar->method('read')->willReturnOnConsecutiveCalls(
            ['success' => true, 'data' => ['lines' => []]],
            ['success' => true, 'data' => ['lines' => []]],
            ['success' => true, 'data' => ['lines' => []]],
            ['success' => true, 'data' => ['lines' => ['1/3/12:4 enable enable working 1(GPON)']]],
        );
        $sidecar->expects($this->once())->method('addPppoe')->willReturn(['success' => true, 'data' => []]);
        $this->app->instance(OltSidecarClient::class, $sidecar);

        $result = app(TestCredentialSyncService::class)->updatePackage($customer, $group->id, 'newpass');

        $this->assertSame(10, $result['vlan_pppoe']);
    }

    public function test_gives_up_after_exhausting_all_poll_attempts_and_never_calls_add_pppoe(): void
    {
        $customer = $this->makeTestCustomer();
        $group = $this->makeGroupWithPool('vlan10-PPPoE', 'PPPOE-REMOTE');

        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('deleteOnu')->willReturn(['success' => true, 'data' => []]);
        $sidecar->method('activateOnu')->willReturn(['success' => true, 'data' => []]);
        $sidecar->method('read')->willReturn(['success' => true, 'data' => ['lines' => []]]);
        $sidecar->expects($this->never())->method('addPppoe');
        $this->app->instance(OltSidecarClient::class, $sidecar);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("tidak menunjukkan status 'working'");

        app(TestCredentialSyncService::class)->updatePackage($customer, $group->id, 'newpass');
    }

    public function test_extracts_vlan_number_from_various_interface_name_shapes(): void
    {
        $customer = $this->makeTestCustomer();
        $group = $this->makeGroupWithPool('vlan111-PPPoE-10Mbps-Loyalis', 'HomeFixed-10Mbps (pool)');

        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('deleteOnu')->willReturn(['success' => true, 'data' => []]);
        $sidecar->method('activateOnu')->willReturnCallback(function ($olt, $params) {
            $this->assertSame(111, $params['vlan_pppoe']);

            return ['success' => true, 'data' => []];
        });
        $sidecar->method('read')->willReturnOnConsecutiveCalls(
            ['success' => true, 'data' => ['lines' => []]],
            ['success' => true, 'data' => ['lines' => ['1/3/12:4 enable enable working 1(GPON)']]],
        );
        $sidecar->method('addPppoe')->willReturn(['success' => true, 'data' => []]);
        $this->app->instance(OltSidecarClient::class, $sidecar);

        $result = app(TestCredentialSyncService::class)->updatePackage($customer, $group->id, 'newpass');

        $this->assertSame(111, $result['vlan_pppoe']);
    }

    // ================================================================
    // v0.23.5 (Opsi B) — applyWanConfig() dari tabel test_onu_wan_configs
    // ================================================================

    /**
     * @param  array<int>  $attachedVlans
     */
    private function makeWanConfig(Customer $customer, array $attachedVlans, array $overrides = []): TestOnuWanConfig
    {
        // v0.23.5 (OPSI a): Paket/VLAN/username/password DIDERIVE LIVE dari
        // customer — paket di-set via customers.ppp_package_id (bukan lagi
        // kolom di test_onu_wan_configs). TestOnuWanConfig hanya menyimpan
        // onu_mode/wan_mode/config_method + attached vlans.
        $nas = Nas::factory()->create();
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'PPPOE-REMOTE']);
        $group = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id,
            'type' => NetworkProfileGroupType::Ppp,
            'customer_ip_pool_id' => $pool->id,
            'interface_name' => 'vlan111-PPPoE-10Mbps-Loyalis',
        ]);
        $package = PppPackage::factory()->create(['network_profile_group_id' => $group->id]);
        $customer->forceFill(['ppp_package_id' => $package->id])->save();

        $config = TestOnuWanConfig::create(array_merge([
            'customer_id' => $customer->id,
            'onu_mode' => 'routing',
            'wan_mode' => 'pppoe',
            'config_method' => 'omci',
        ], $overrides));

        foreach ($attachedVlans as $vlan) {
            $config->attachedVlans()->create(['vlan_id' => $vlan]);
        }

        return $config;
    }

    private function fullSuccessSidecar(?\Closure $onActivate = null): OltSidecarClient
    {
        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->method('deleteOnu')->willReturn(['success' => true, 'data' => ['commands_applied' => 4]]);
        $reads = 0;
        $sidecar->method('read')->willReturnCallback(function () use (&$reads) {
            $reads++;

            return $reads === 1
                ? ['success' => true, 'data' => ['lines' => []]]
                : ['success' => true, 'data' => ['lines' => ['1/3/12:4 enable enable working 1(GPON)']]];
        });
        $sidecar->method('activateOnu')->willReturnCallback(function ($olt, $params) use ($onActivate) {
            if ($onActivate) {
                $onActivate($params);
            }

            return ['success' => true, 'data' => ['commands_applied' => 27]];
        });
        $sidecar->method('addPppoe')->willReturn(['success' => true, 'data' => ['commands_applied' => 4]]);

        return $sidecar;
    }

    public function test_apply_wan_config_rejects_a_non_test_fixture_customer(): void
    {
        $customer = $this->makeTestCustomer(isTestFixture: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is_test_fixture=true');

        app(TestCredentialSyncService::class)->applyWanConfig($customer);
    }

    public function test_apply_wan_config_rejects_when_there_is_no_wan_config_row(): void
    {
        $customer = $this->makeTestCustomer();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum punya konfigurasi WAN');

        app(TestCredentialSyncService::class)->applyWanConfig($customer);
    }

    public function test_apply_wan_config_rejects_bridging_mode(): void
    {
        $customer = $this->makeTestCustomer();
        $this->makeWanConfig($customer, [9, 172], ['onu_mode' => 'bridging']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum didukung');

        app(TestCredentialSyncService::class)->applyWanConfig($customer);
    }

    public function test_apply_wan_config_rejects_non_pppoe_wan_mode(): void
    {
        $customer = $this->makeTestCustomer();
        $this->makeWanConfig($customer, [9, 172], ['wan_mode' => 'dhcp']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum didukung');

        app(TestCredentialSyncService::class)->applyWanConfig($customer);
    }

    public function test_apply_wan_config_rejects_tr069_config_method_for_now(): void
    {
        $customer = $this->makeTestCustomer();
        $this->makeWanConfig($customer, [9, 172], ['config_method' => 'tr069']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tr069 belum diimplementasikan');

        app(TestCredentialSyncService::class)->applyWanConfig($customer);
    }

    public function test_apply_wan_config_full_success_maps_attached_vlans_and_writes_radcheck(): void
    {
        $customer = $this->makeTestCustomer();
        $this->makeWanConfig($customer, [9, 172]);

        $captured = null;
        $this->app->instance(OltSidecarClient::class, $this->fullSuccessSidecar(function ($params) use (&$captured) {
            $captured = $params;
        }));

        $result = app(TestCredentialSyncService::class)->applyWanConfig($customer);

        $this->assertSame(111, $result['vlan_pppoe']);
        $this->assertSame(172, $result['vlan_bridge']);        // attached non-(9/pppoe) -> slot bridge
        $this->assertSame([], $result['extra_flow_vlans']);    // tidak ada sisa
        $this->assertSame('PPPOE-REMOTE', $result['framed_pool']);
        // activateOnu menerima vlan_mgmt=9 (mandatory), bridge=172, extra kosong
        $this->assertSame(9, $captured['vlan_mgmt']);
        $this->assertSame(172, $captured['vlan_bridge']);
        $this->assertSame([], $captured['extra_flow_vlans']);

        // Username derive dari cid customer; password = konstanta sistem.
        $this->assertSame("{$customer->cid}@ppp.bajastu.id", $result['username']);
        $check = DB::connection('radius')->table('radcheck')
            ->where('username', "{$customer->cid}@ppp.bajastu.id")->first();
        $this->assertSame('wifijadipasti', $check->value);
    }

    public function test_apply_wan_config_enforces_mandatory_vlan_9_even_when_not_in_pivot(): void
    {
        $customer = $this->makeTestCustomer();
        // Sengaja TIDAK memasukkan VLAN 9 ke pivot — service harus memaksanya.
        $this->makeWanConfig($customer, [172]);

        $captured = null;
        $this->app->instance(OltSidecarClient::class, $this->fullSuccessSidecar(function ($params) use (&$captured) {
            $captured = $params;
        }));

        $result = app(TestCredentialSyncService::class)->applyWanConfig($customer);

        $this->assertContains(9, $result['attached_vlans']);   // dipaksa masuk
        $this->assertSame(9, $captured['vlan_mgmt']);
    }

    public function test_apply_wan_config_routes_extra_attached_vlans_to_flow_permission_lines(): void
    {
        $customer = $this->makeTestCustomer();
        // 9(mgmt) + 172(bridge) + 131,150 (extra flow permission).
        $this->makeWanConfig($customer, [9, 172, 131, 150]);

        $captured = null;
        $this->app->instance(OltSidecarClient::class, $this->fullSuccessSidecar(function ($params) use (&$captured) {
            $captured = $params;
        }));

        $result = app(TestCredentialSyncService::class)->applyWanConfig($customer);

        $this->assertSame(172, $result['vlan_bridge']);          // pertama non-(9/pppoe)
        $this->assertSame([131, 150], $result['extra_flow_vlans']); // sisanya -> flow permission
        $this->assertSame([131, 150], $captured['extra_flow_vlans']);
    }

    public function test_apply_wan_config_rejects_when_customer_has_no_vlan_package(): void
    {
        $customer = $this->makeTestCustomer();
        // TestOnuWanConfig ada, TAPI customer.ppp_package_id tetap null
        // (tidak lewat makeWanConfig yang men-set paket ber-VLAN).
        TestOnuWanConfig::create([
            'customer_id' => $customer->id, 'onu_mode' => 'routing',
            'wan_mode' => 'pppoe', 'config_method' => 'omci',
        ]);

        $sidecar = $this->createMock(OltSidecarClient::class);
        $sidecar->expects($this->never())->method('activateOnu');
        $this->app->instance(OltSidecarClient::class, $sidecar);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum punya paket dengan VLAN');

        app(TestCredentialSyncService::class)->applyWanConfig($customer);
    }

    public function test_derive_live_reflects_customer_package_change_without_stored_copy(): void
    {
        $customer = $this->makeTestCustomer();
        $this->makeWanConfig($customer, [9, 172]); // set paket VLAN 111

        $svc = app(TestCredentialSyncService::class);
        $this->assertSame(111, $svc->deriveLiveWanParams($customer->fresh())['vlan_pppoe']);

        // Ganti paket pelanggan ke grup VLAN 10 — derive live harus ikut,
        // TANPA menyentuh test_onu_wan_configs (tidak ada salinan tersimpan).
        $nas = Nas::factory()->create();
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'PPPOE-REMOTE']);
        $group = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id, 'type' => NetworkProfileGroupType::Ppp,
            'customer_ip_pool_id' => $pool->id, 'interface_name' => 'vlan10-PPPoE',
        ]);
        $pkg = PppPackage::factory()->create(['network_profile_group_id' => $group->id]);
        $customer->forceFill(['ppp_package_id' => $pkg->id])->save();

        $this->assertSame(10, $svc->deriveLiveWanParams($customer->fresh())['vlan_pppoe']);
    }
}
