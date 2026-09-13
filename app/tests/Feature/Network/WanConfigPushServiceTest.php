<?php

namespace Tests\Feature\Network;

use App\Enums\CpeActionStatus;
use App\Models\BandwidthProfile;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\ModemType;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WanConfigTemplate;
use App\Services\Network\WanConfigPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v0.12.6 Bagian 1 — WanConfigPushService::push(), semua state: skip
 * (tidak ada Template cocok / WAN1 tidak diaktifkan / device belum punya
 * WAN1 PPPoE aktif), delivered (setParameterValues genuinely terkirim ke
 * path yang di-resolve live), dan failed (sendTask melempar exception).
 *
 * Http::fake() pola PERSIS CpeParameterResolverServiceTest — GET
 * `*genieacs-nbi*` untuk findDeviceById(), POST ke path yang sama untuk
 * sendTask() (dibedakan lewat method HTTP, bukan URL, sama seperti
 * GenieAcsClientService sendiri memperlakukan base URL yang sama untuk
 * kedua operasi).
 */
class WanConfigPushServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WanConfigPushService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->service = app(WanConfigPushService::class);
    }

    private function actor(): User
    {
        return User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function package(): PppPackage
    {
        $group = NetworkProfileGroup::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        return PppPackage::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $this->tenant->id])->id,
        ]);
    }

    private function deviceWithActiveWan1(string $genieAcsDeviceId, string $username = 'olduser'): array
    {
        return [
            '_id' => $genieAcsDeviceId,
            '_deviceId' => [
                '_Manufacturer' => 'ZICG',
                '_OUI' => 'F86CE1',
                '_ProductClass' => 'F663NV3a',
                '_SerialNumber' => 'ZICG296C2E7B',
            ],
            'InternetGatewayDevice' => [
                'WANDevice' => [
                    '1' => [
                        'WANConnectionDevice' => [
                            '1' => [
                                'WANPPPConnection' => [
                                    '1' => [
                                        'Username' => ['_value' => $username, '_type' => 'xsd:string'],
                                        'ConnectionType' => ['_value' => 'IP_Routed', '_type' => 'xsd:string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_skips_when_customer_has_no_package(): void
    {
        Http::fake();

        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'ppp_package_id' => null]);
        $device = CpeDevice::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);

        $log = $this->service->push($device, $this->actor());

        $this->assertSame(CpeActionStatus::Skipped, $log->status);
        $this->assertStringContainsString('Tidak ada Template Konfig CPE', $log->failed_reason);
        Http::assertNothingSent();
    }

    public function test_skips_when_no_matching_template_exists_for_the_package_and_modem_type(): void
    {
        Http::fake();

        $package = $this->package();
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'ppp_package_id' => $package->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);

        $log = $this->service->push($device, $this->actor());

        $this->assertSame(CpeActionStatus::Skipped, $log->status);
        Http::assertNothingSent();
    }

    public function test_skips_when_template_found_but_wan1_is_not_enabled(): void
    {
        Http::fake();

        $package = $this->package();
        WanConfigTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'ppp_package_id' => $package->id,
            'modem_type_id' => null,
            'wan1_enabled' => false,
        ]);
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'ppp_package_id' => $package->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);

        $log = $this->service->push($device, $this->actor());

        $this->assertSame(CpeActionStatus::Skipped, $log->status);
        $this->assertStringContainsString('WAN1 tidak diaktifkan', $log->failed_reason);
        Http::assertNothingSent();
    }

    public function test_skips_when_device_has_never_connected_to_genieacs(): void
    {
        Http::fake();

        $package = $this->package();
        WanConfigTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'ppp_package_id' => $package->id,
            'modem_type_id' => null,
            'wan1_enabled' => true,
        ]);
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'ppp_package_id' => $package->id]);
        $device = CpeDevice::factory()->pendingFirstConnect()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        $log = $this->service->push($device, $this->actor());

        $this->assertSame(CpeActionStatus::Skipped, $log->status);
        $this->assertStringContainsString('belum punya WAN1 PPPoE yang genuinely aktif', $log->failed_reason);
        Http::assertNothingSent();
    }

    public function test_skips_when_device_has_connected_but_has_no_active_wan1_ppp_connection_yet(): void
    {
        $package = $this->package();
        WanConfigTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'ppp_package_id' => $package->id,
            'modem_type_id' => null,
            'wan1_enabled' => true,
        ]);
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'ppp_package_id' => $package->id]);
        $device = CpeDevice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
        ]);

        Http::fake([
            '*genieacs-nbi*' => Http::response([[
                '_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
                '_deviceId' => ['_Manufacturer' => 'ZICG', '_OUI' => 'F86CE1', '_ProductClass' => 'F663NV3a', '_SerialNumber' => 'ZICG296C2E7B'],
            ]], 200),
        ]);

        $log = $this->service->push($device, $this->actor());

        $this->assertSame(CpeActionStatus::Skipped, $log->status);
        $this->assertStringContainsString('belum punya WAN1 PPPoE yang genuinely aktif', $log->failed_reason);
        Http::assertSentCount(1); // hanya findDeviceById, tidak pernah sampai sendTask()
    }

    public function test_delivered_pushes_the_new_credentials_to_the_live_resolved_path(): void
    {
        $package = $this->package();
        $template = WanConfigTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'ppp_package_id' => $package->id,
            'modem_type_id' => null,
            'wan1_enabled' => true,
            'wan1_pppoe_username' => 'newuser',
            'wan1_pppoe_password' => 'newpass',
        ]);
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'ppp_package_id' => $package->id]);
        $device = CpeDevice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
        ]);

        Http::fake([
            '*/devices/F86CE1-F663NV3a-ZICG296C2E7B/tasks*' => Http::response(['_id' => 'task-abc123'], 202),
            '*genieacs-nbi*' => Http::response([$this->deviceWithActiveWan1('F86CE1-F663NV3a-ZICG296C2E7B')], 200),
        ]);

        $actor = $this->actor();
        $log = $this->service->push($device, $actor);

        $this->assertSame(CpeActionStatus::Delivered, $log->status);
        $this->assertSame('task-abc123', $log->genieacs_task_id);
        $this->assertSame($actor->id, $log->performed_by);
        $this->assertSame(
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1',
            $log->parameters['target_path']
        );
        $this->assertSame($template->id, $log->parameters['wan_config_template_id']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/tasks')) {
                return false;
            }

            $values = $request->data()['parameterValues'] ?? [];

            return $request->data()['name'] === 'setParameterValues'
                && $values[0][0] === 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username'
                && $values[0][1] === 'newuser'
                && $values[1][0] === 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password'
                && $values[1][1] === 'newpass';
        });
    }

    public function test_marks_failed_when_send_task_throws(): void
    {
        $package = $this->package();
        WanConfigTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'ppp_package_id' => $package->id,
            'modem_type_id' => null,
            'wan1_enabled' => true,
        ]);
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'ppp_package_id' => $package->id]);
        $device = CpeDevice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
        ]);

        Http::fake([
            '*/devices/F86CE1-F663NV3a-ZICG296C2E7B/tasks*' => Http::response(['error' => 'boom'], 500),
            '*genieacs-nbi*' => Http::response([$this->deviceWithActiveWan1('F86CE1-F663NV3a-ZICG296C2E7B')], 200),
        ]);

        $log = $this->service->push($device, $this->actor());

        $this->assertSame(CpeActionStatus::Failed, $log->status);
        $this->assertNotNull($log->failed_reason);
    }

    public function test_matches_the_modem_type_specific_template_over_the_default_when_both_exist(): void
    {
        $package = $this->package();
        $modemType = ModemType::factory()->create(['tenant_id' => $this->tenant->id]);

        WanConfigTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'ppp_package_id' => $package->id,
            'modem_type_id' => null,
            'wan1_enabled' => true,
            'wan1_pppoe_username' => 'default-user',
        ]);
        $specific = WanConfigTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'ppp_package_id' => $package->id,
            'modem_type_id' => $modemType->id,
            'wan1_enabled' => true,
            'wan1_pppoe_username' => 'specific-user',
        ]);

        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'ppp_package_id' => $package->id]);
        $device = CpeDevice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'modem_type_id' => $modemType->id,
            'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
        ]);

        Http::fake([
            '*/devices/F86CE1-F663NV3a-ZICG296C2E7B/tasks*' => Http::response(['_id' => 'task-xyz'], 202),
            '*genieacs-nbi*' => Http::response([$this->deviceWithActiveWan1('F86CE1-F663NV3a-ZICG296C2E7B')], 200),
        ]);

        $log = $this->service->push($device, $this->actor());

        $this->assertSame(CpeActionStatus::Delivered, $log->status);
        $this->assertSame($specific->id, $log->parameters['wan_config_template_id']);
    }
}
