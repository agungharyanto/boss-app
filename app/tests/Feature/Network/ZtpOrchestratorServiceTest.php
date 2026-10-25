<?php

namespace Tests\Feature\Network;

use App\Enums\SsidBand;
use App\Enums\SsidSecurity;
use App\Enums\WanMode;
use App\Enums\WifiRole;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\OltDevice;
use App\Models\PppPackage;
use App\Models\ProvisioningProfile;
use App\Models\ProvisioningSsid;
use App\Models\ProvisioningWanBridge;
use App\Models\ProvisioningWanInternet;
use App\Models\Tenant;
use App\Services\Network\Provisioning\AdapterCapabilities;
use App\Services\Network\Provisioning\Contracts\CpeProvisioningAdapter;
use App\Services\Network\Provisioning\Contracts\OltProvisioningAdapter;
use App\Services\Network\Provisioning\CpeAdapterRegistry;
use App\Services\Network\Provisioning\Exceptions\UnsupportedAdapterOperation;
use App\Services\Network\Provisioning\OltAdapterRegistry;
use App\Services\Network\Provisioning\ProvisioningProfileResolver;
use App\Services\Network\Provisioning\SsidNameResolver;
use App\Services\Network\Provisioning\ZtpOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.23.8.5a — orkestrator ZTP, logic murni (adapter di-mock, nol sentuh device).
 */
class ZtpOrchestratorServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CpeProvisioningAdapter $cpeAdapter;

    private OltProvisioningAdapter $oltAdapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->cpeAdapter = $this->createMock(CpeProvisioningAdapter::class);
        $this->oltAdapter = $this->createMock(OltProvisioningAdapter::class);
    }

    private function orchestrator(string $wanInternetVia = 'tr069_device_driven'): ZtpOrchestratorService
    {
        $this->oltAdapter->method('capabilities')->willReturn(
            new AdapterCapabilities(['naming' => true, 'save' => false], wanInternetVia: $wanInternetVia)
        );

        $cpeReg = $this->createMock(CpeAdapterRegistry::class);
        $cpeReg->method('for')->willReturn($this->cpeAdapter);
        $oltReg = $this->createMock(OltAdapterRegistry::class);
        $oltReg->method('for')->willReturn($this->oltAdapter);

        return new ZtpOrchestratorService(
            app(ProvisioningProfileResolver::class),
            $cpeReg,
            $oltReg,
            new SsidNameResolver,
        );
    }

    /** @return array{profile: ProvisioningProfile, package: PppPackage} */
    private function makeProfile(bool $withBridge = true, bool $withSsid = true, bool $attachDefault = true): array
    {
        $profile = ProvisioningProfile::create(['tenant_id' => $this->tenant->id, 'name' => 'Home', 'slug' => 'home', 'enabled' => true]);
        ProvisioningWanInternet::create(['provisioning_profile_id' => $profile->id, 'wan_mode' => WanMode::Pppoe, 'vlan' => 10, 'wifi_role' => WifiRole::PrimaryWifi]);
        if ($withBridge) {
            ProvisioningWanBridge::create(['provisioning_profile_id' => $profile->id, 'vlan' => 172, 'wifi_role' => WifiRole::GuestWifi]);
        }
        if ($withSsid) {
            ProvisioningSsid::create(['provisioning_profile_id' => $profile->id, 'wifi_role' => WifiRole::PrimaryWifi, 'band' => SsidBand::Band24, 'ssid_name' => 'WiFi-{cid}', 'security_mode' => SsidSecurity::Wpa2, 'password' => 'secret12']);
            ProvisioningSsid::create(['provisioning_profile_id' => $profile->id, 'wifi_role' => WifiRole::GuestWifi, 'band' => SsidBand::Band24, 'ssid_name' => 'TOKEN WIFI', 'security_mode' => SsidSecurity::Open]);
        }
        $package = PppPackage::factory()->create(['tenant_id' => $this->tenant->id]);
        if ($attachDefault) {
            $package->provisioningProfiles()->attach($profile->id, ['is_default' => true]);
        }

        return ['profile' => $profile, 'package' => $package];
    }

    private function makeCpe(?PppPackage $package, string $manufacturer = 'CMDC'): CpeDevice
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'ppp_package_id' => $package?->id, 'cid' => '26199', 'name' => 'Budi']);

        return CpeDevice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'manufacturer' => $manufacturer,
            'serial_number' => 'CMDCTESTSN01',
            'genieacs_device_id' => 'DEV',
        ]);
    }

    private function olt(): OltDevice
    {
        // Partial mock — orkestrator hanya memanggil sidecarVendorKey() di OLT asli;
        // operasi device didelegasikan ke $this->oltAdapter (mock).
        $olt = $this->createPartialMock(OltDevice::class, ['sidecarVendorKey']);
        $olt->method('sidecarVendorKey')->willReturn('hsgq_g02id');

        return $olt;
    }

    // ---- tests ------------------------------------------------------------

    public function test_full_happy_path_runs_all_stages_in_order(): void
    {
        $data = $this->makeProfile();
        $cpe = $this->makeCpe($data['package']);
        $cid = $cpe->customer->cid; // cid di-auto-generate observer — baca nilai nyata

        $this->cpeAdapter->expects($this->once())->method('pushWanInternet')->willReturn(['wan' => 'ok']);
        $this->cpeAdapter->expects($this->once())->method('pushWanBridge')->willReturn(['bridge_present' => true]);
        $this->cpeAdapter->expects($this->once())->method('pushSsid')
            ->with($this->anything(), $this->callback(function (array $specs) use ($cid): bool {
                // {cid} ter-resolve ke cid pelanggan nyata (order-independent).
                $names = array_column($specs, 'ssid_name');

                return count($specs) === 2
                    && in_array("WiFi-{$cid}", $names, true)
                    && in_array('TOKEN WIFI', $names, true);
            }))
            ->willReturn(['configured' => [1], 'pending' => [4], 'addobject_issued' => true]);
        $this->cpeAdapter->expects($this->once())->method('disableUnboundWlan')
            ->willReturn(['existing' => [1, 2, 3, 4], 'kept' => [1, 4], 'disabled' => [2, 3]]);
        $this->oltAdapter->expects($this->once())->method('applyNaming')->willReturn(['named_inline' => true]);
        $this->oltAdapter->expects($this->once())->method('saveConfig')->willReturn(['saved' => true]);
        // Orkestrator TIDAK PERNAH memanggil admit() (admisi = alur device terpisah/8.5b).
        $this->oltAdapter->expects($this->never())->method('admit');

        $result = $this->orchestrator()->provision($cpe, $this->olt());

        $this->assertTrue($result['ok']);
        $this->assertSame('home', $result['profile']['slug']);
        $this->assertSame('package_default', $result['profile']['resolved_via']);
        $this->assertSame(
            ['wan_internet', 'wan_bridge', 'ssid', 'disable_unbound_wlan', 'naming', 'save'],
            array_column($result['stages'], 'stage'),
        );
        $this->assertSame('tr069_device_driven', $result['stages'][0]['via']);
        $this->assertFalse($result['rollback_safe']);
    }

    public function test_stages_option_runs_only_requested_wan_stages(): void
    {
        $data = $this->makeProfile();
        $cpe = $this->makeCpe($data['package']);

        $this->cpeAdapter->expects($this->once())->method('pushWanInternet')->willReturn(['wan' => 'ok']);
        $this->cpeAdapter->expects($this->once())->method('pushWanBridge')->willReturn(['bridge_present' => true]);
        $this->cpeAdapter->expects($this->never())->method('pushSsid');
        $this->cpeAdapter->expects($this->never())->method('disableUnboundWlan');
        $this->oltAdapter->expects($this->never())->method('applyNaming');
        $this->oltAdapter->expects($this->never())->method('saveConfig');

        $result = $this->orchestrator()->provision($cpe, $this->olt(), ['stages' => ['wan_internet', 'wan_bridge']]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['wan_internet', 'wan_bridge'], array_column($result['stages'], 'stage'));
    }

    public function test_stages_option_runs_only_requested_ssid_and_olt_stages(): void
    {
        $data = $this->makeProfile();
        $cpe = $this->makeCpe($data['package']);

        $this->cpeAdapter->expects($this->never())->method('pushWanInternet');
        $this->cpeAdapter->expects($this->never())->method('pushWanBridge');
        $this->cpeAdapter->expects($this->once())->method('pushSsid')->willReturn(['configured' => [4], 'pending' => [], 'skipped' => [], 'addobject_issued' => false]);
        $this->cpeAdapter->expects($this->once())->method('disableUnboundWlan')->willReturn(['existing' => [1, 2, 3, 4], 'kept' => [4], 'disabled' => [1, 2, 3]]);
        $this->oltAdapter->expects($this->once())->method('applyNaming')->willReturn(['named' => true]);
        $this->oltAdapter->expects($this->once())->method('saveConfig')->willReturn(['saved' => true]);

        $result = $this->orchestrator()->provision($cpe, $this->olt(), ['stages' => ['ssid', 'disable_unbound_wlan', 'naming', 'save']]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['ssid', 'disable_unbound_wlan', 'naming', 'save'], array_column($result['stages'], 'stage'));
    }

    public function test_resolver_failure_stops_with_resolver_reason(): void
    {
        // Paket tanpa profil -> no_profile.
        $data = $this->makeProfile(attachDefault: false); // profil ada tapi TIDAK di-attach ke paket
        $cpe = $this->makeCpe($data['package']);

        $this->cpeAdapter->expects($this->never())->method('pushWanInternet');
        $this->oltAdapter->expects($this->never())->method('saveConfig');

        $result = $this->orchestrator()->provision($cpe, $this->olt());

        $this->assertFalse($result['ok']);
        $this->assertSame('resolve_profile', $result['stage']);
        $this->assertSame('no_profile', $result['source']);
        $this->assertNotNull($result['reason']);
        $this->assertSame([], $result['stages']);
    }

    public function test_resolver_ambiguous_stops(): void
    {
        $package = PppPackage::factory()->create(['tenant_id' => $this->tenant->id]);
        foreach (['a', 'b'] as $slug) {
            $p = ProvisioningProfile::create(['tenant_id' => $this->tenant->id, 'name' => $slug, 'slug' => $slug, 'enabled' => true]);
            $package->provisioningProfiles()->attach($p->id, ['is_default' => false]);
        }
        $cpe = $this->makeCpe($package);

        $result = $this->orchestrator()->provision($cpe, $this->olt());
        $this->assertFalse($result['ok']);
        $this->assertSame('resolve_profile', $result['stage']);
        $this->assertSame('ambiguous', $result['source']);
    }

    public function test_mid_stage_failure_reports_prior_stages_and_no_rollback(): void
    {
        $data = $this->makeProfile();
        $cpe = $this->makeCpe($data['package']);

        $this->cpeAdapter->method('pushWanInternet')->willReturn(['wan' => 'ok']);
        $this->cpeAdapter->method('pushWanBridge')->willReturn(['bridge_present' => true]);
        $this->cpeAdapter->method('pushSsid')->willThrowException(new RuntimeException('SSID gagal'));
        $this->oltAdapter->expects($this->never())->method('applyNaming');
        $this->oltAdapter->expects($this->never())->method('saveConfig');

        $result = $this->orchestrator()->provision($cpe, $this->olt());

        $this->assertFalse($result['ok']);
        $this->assertSame('ssid', $result['failed_stage']);
        $stages = collect($result['stages']);
        $this->assertTrue($stages->firstWhere('stage', 'wan_internet')['ok']);
        $this->assertTrue($stages->firstWhere('stage', 'wan_bridge')['ok']);
        $this->assertFalse($stages->firstWhere('stage', 'ssid')['ok']);
        $this->assertSame('SSID gagal', $stages->firstWhere('stage', 'ssid')['error']);
        $this->assertNull($stages->firstWhere('stage', 'naming'));
        $this->assertFalse($result['rollback_safe']);
    }

    public function test_omci_via_routes_wan_internet_to_olt_adapter(): void
    {
        $data = $this->makeProfile(withBridge: false, withSsid: false);
        $cpe = $this->makeCpe($data['package']);

        // via=omci -> WAN internet lewat OLT adapter, BUKAN cpe adapter.
        $this->oltAdapter->expects($this->once())->method('pushWanInternetOmci')
            ->with($this->anything(), $this->callback(fn (array $spec): bool => ($spec['vlan_pppoe'] ?? null) === 10))
            ->willReturn(['success' => true]);
        $this->cpeAdapter->expects($this->never())->method('pushWanInternet');
        $this->oltAdapter->method('applyNaming')->willReturn(['named_inline' => true]);
        $this->oltAdapter->method('saveConfig')->willReturn(['saved' => true]);

        $result = $this->orchestrator('omci')->provision($cpe, $this->olt(), [
            'onu_id' => 5, 'onu_type' => 'ZTE-F660', 'tcont_profile' => 'HomeFixed-10Mbps',
            'traffic_profile' => 'PPPoE-Remote', 'vlan_mgmt' => 9, 'vlan_bridge' => 172, 'pon_interface' => '1/3/12',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('omci', $result['stages'][0]['via']);
    }

    public function test_unknown_cpe_brand_fails_at_select_adapter(): void
    {
        $data = $this->makeProfile();
        $cpe = $this->makeCpe($data['package'], manufacturer: 'Nokia');

        // cpeBrandKey melempar utk merek tak dikenal -> tahap select_adapter.
        $result = $this->orchestrator()->provision($cpe, $this->olt());
        $this->assertFalse($result['ok']);
        $this->assertSame('select_adapter', $result['stage']);
    }

    // ---- LANGKAH D: kanal provisioning per-OLT ----------------------------

    public function test_omci_channel_disabled_blocks_before_any_stage(): void
    {
        // Profil WAN-internet-only via OMCI + OLT omci_channel_enabled=false.
        $data = $this->makeProfile(withBridge: false, withSsid: false);
        $cpe = $this->makeCpe($data['package']);
        $olt = $this->olt();
        $olt->omci_channel_enabled = false;

        $this->oltAdapter->expects($this->never())->method('pushWanInternetOmci');
        $this->cpeAdapter->expects($this->never())->method('pushWanInternet');

        $result = $this->orchestrator('omci')->provision($cpe, $olt);

        $this->assertFalse($result['ok']);
        $this->assertSame('channel_check', $result['stage']);
        $this->assertSame(['omci'], $result['disabled_channels']);
        $this->assertSame([], $result['stages']);
    }

    public function test_tr069_channel_disabled_blocks_before_any_stage(): void
    {
        // Profil default (WAN via TR-069 + bridge + SSID) + tr069 dimatikan.
        $data = $this->makeProfile();
        $cpe = $this->makeCpe($data['package']);
        $olt = $this->olt();
        $olt->tr069_channel_enabled = false;

        $this->cpeAdapter->expects($this->never())->method('pushWanInternet');
        $this->cpeAdapter->expects($this->never())->method('pushSsid');

        $result = $this->orchestrator()->provision($cpe, $olt);

        $this->assertFalse($result['ok']);
        $this->assertSame('channel_check', $result['stage']);
        $this->assertSame(['tr069'], $result['disabled_channels']);
    }

    public function test_cpe_brand_key_maps_the_real_device_families(): void
    {
        $svc = $this->orchestrator();
        $m = new \ReflectionMethod($svc, 'cpeBrandKey');
        $m->setAccessible(true);
        $cpe = function (string $mfr): CpeDevice {
            $c = new CpeDevice;
            $c->manufacturer = $mfr;

            return $c;
        };

        $this->assertSame('ctcom', $m->invoke($svc, $cpe('CMDC')));
        $this->assertSame('ctcom', $m->invoke($svc, $cpe('CMDC H3-2S XPON')));
        $this->assertSame('zte_generic', $m->invoke($svc, $cpe('ZTE')));
        $this->assertSame('huawei', $m->invoke($svc, $cpe('Huawei Technologies')));

        $this->expectException(UnsupportedAdapterOperation::class);
        $m->invoke($svc, $cpe('Nokia'));
    }
}
