<?php

namespace Tests\Feature\Network;

use App\Enums\CpeDeviceStatus;
use App\Models\CpeDevice;
use App\Models\Nas;
use App\Models\Tenant;
use App\Services\Network\Contracts\RouterOsGateway;
use App\Services\Network\CpeDeviceStatusSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CpeDeviceStatusSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $devices
     */
    private function fakeGenieAcsDevices(array $devices): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices*' => Http::response($devices, 200),
        ]);
    }

    private function genieAcsDevice(string $id, string $connectionRequestUrl, ?string $lastInform = null): array
    {
        return [
            '_id' => $id,
            '_lastInform' => $lastInform ?? now()->toIso8601String(),
            'InternetGatewayDevice' => [
                'ManagementServer' => [
                    'ConnectionRequestURL' => ['_value' => $connectionRequestUrl],
                ],
            ],
        ];
    }

    private function vantageNas(): Nas
    {
        return Nas::factory()->create(['name' => 'test-x86-bajastu']);
    }

    /**
     * @param  array<string, bool>  $reachabilityByIp  keyed by target IP
     */
    private function fakePing(array $reachabilityByIp): void
    {
        $fake = new class($reachabilityByIp) implements RouterOsGateway
        {
            /** @var array<int, string> */
            public array $pingedIps = [];

            public function __construct(private array $reachabilityByIp) {}

            public function ping(Nas $nas): array
            {
                return ['online' => true, 'message' => null];
            }

            public function pingHost(Nas $nas, string $targetIp, int $count = 2): bool
            {
                $this->pingedIps[] = $targetIp;

                return $this->reachabilityByIp[$targetIp] ?? false;
            }

            public function provisionApiUser(Nas $nas, string $connectAsUsername, string $connectAsPassword, string $newApiUsername, string $newApiPassword): array
            {
                return ['success' => true, 'message' => null];
            }
        };

        app()->instance(RouterOsGateway::class, $fake);
    }

    public function test_device_reachable_via_router_ping_is_marked_online(): void
    {
        $this->vantageNas();
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'OUI-PC-REACHABLE',
            'status' => CpeDeviceStatus::Offline,
        ]);

        $this->fakeGenieAcsDevices([
            $this->genieAcsDevice('OUI-PC-REACHABLE', 'http://10.1.1.5:58000'),
        ]);
        $this->fakePing(['10.1.1.5' => true]);

        $result = app(CpeDeviceStatusSyncService::class)->syncAll();

        $this->assertSame(1, $result['synced']);
        $this->assertSame(1, $result['online']);
        $this->assertSame(0, $result['offline']);
        $this->assertSame(CpeDeviceStatus::Online, $device->fresh()->status);
    }

    public function test_device_unreachable_via_router_ping_is_marked_offline(): void
    {
        $this->vantageNas();
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'OUI-PC-UNREACHABLE',
            'status' => CpeDeviceStatus::Online,
        ]);

        $this->fakeGenieAcsDevices([
            $this->genieAcsDevice('OUI-PC-UNREACHABLE', 'http://10.1.1.9:58000'),
        ]);
        $this->fakePing(['10.1.1.9' => false]);

        $result = app(CpeDeviceStatusSyncService::class)->syncAll();

        $this->assertSame(1, $result['synced']);
        $this->assertSame(0, $result['online']);
        $this->assertSame(1, $result['offline']);
        $this->assertSame(CpeDeviceStatus::Offline, $device->fresh()->status);
    }

    public function test_ping_targets_the_ip_parsed_from_connection_request_url_not_the_full_url(): void
    {
        $this->vantageNas();
        $tenant = Tenant::factory()->create();
        CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'OUI-PC-PARSE',
        ]);

        $this->fakeGenieAcsDevices([
            $this->genieAcsDevice('OUI-PC-PARSE', 'http://10.1.12.185:58000'),
        ]);
        $fake = new class implements RouterOsGateway
        {
            public array $pingedIps = [];

            public function ping(Nas $nas): array
            {
                return ['online' => true, 'message' => null];
            }

            public function pingHost(Nas $nas, string $targetIp, int $count = 2): bool
            {
                $this->pingedIps[] = $targetIp;

                return true;
            }

            public function provisionApiUser(Nas $nas, string $connectAsUsername, string $connectAsPassword, string $newApiUsername, string $newApiPassword): array
            {
                return ['success' => true, 'message' => null];
            }
        };
        app()->instance(RouterOsGateway::class, $fake);

        app(CpeDeviceStatusSyncService::class)->syncAll();

        $this->assertSame(['10.1.12.185'], $fake->pingedIps);
    }

    public function test_status_changed_at_only_updates_on_a_real_transition(): void
    {
        $this->vantageNas();
        $tenant = Tenant::factory()->create();
        $frozenChangeTime = now()->subDays(3)->startOfSecond();
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'OUI-PC-STABLE',
            'status' => CpeDeviceStatus::Online,
            'status_changed_at' => $frozenChangeTime,
        ]);

        $this->fakeGenieAcsDevices([
            $this->genieAcsDevice('OUI-PC-STABLE', 'http://10.1.1.5:58000'),
        ]);
        // Still reachable — status doesn't actually change this run.
        $this->fakePing(['10.1.1.5' => true]);

        app(CpeDeviceStatusSyncService::class)->syncAll();

        $device->refresh();
        $this->assertSame(CpeDeviceStatus::Online, $device->status);
        $this->assertTrue($device->status_changed_at->equalTo($frozenChangeTime));
    }

    public function test_status_changed_at_is_stamped_the_moment_status_flips(): void
    {
        $this->vantageNas();
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'OUI-PC-FLIPPING',
            'status' => CpeDeviceStatus::Online,
            'status_changed_at' => now()->subDays(10),
        ]);

        $this->fakeGenieAcsDevices([
            $this->genieAcsDevice('OUI-PC-FLIPPING', 'http://10.1.1.5:58000'),
        ]);
        $this->fakePing(['10.1.1.5' => false]);

        $before = now()->startOfSecond();
        app(CpeDeviceStatusSyncService::class)->syncAll();

        $device->refresh();
        $this->assertSame(CpeDeviceStatus::Offline, $device->status);
        $this->assertTrue($device->status_changed_at->greaterThanOrEqualTo($before));
    }

    public function test_last_inform_at_is_still_synced_from_genieacs(): void
    {
        $this->vantageNas();
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'OUI-PC-INFORM',
            'last_inform_at' => now()->subDays(5),
        ]);

        $realTimestamp = now()->subHours(3)->startOfSecond();
        $this->fakeGenieAcsDevices([
            $this->genieAcsDevice('OUI-PC-INFORM', 'http://10.1.1.5:58000', $realTimestamp->toIso8601String()),
        ]);
        $this->fakePing(['10.1.1.5' => true]);

        app(CpeDeviceStatusSyncService::class)->syncAll();

        $this->assertTrue($device->fresh()->last_inform_at->equalTo($realTimestamp));
    }

    public function test_pending_first_connect_devices_are_never_touched(): void
    {
        $this->vantageNas();
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->pendingFirstConnect()->create([
            'tenant_id' => $tenant->id,
        ]);
        $originalStatus = $device->status;

        $this->fakeGenieAcsDevices([]);
        $this->fakePing([]);

        $result = app(CpeDeviceStatusSyncService::class)->syncAll();

        $this->assertSame(0, $result['synced']);
        $this->assertSame($originalStatus, $device->fresh()->status);
    }

    public function test_device_with_no_usable_connection_request_url_is_skipped(): void
    {
        $this->vantageNas();
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'OUI-PC-NOURL',
            'status' => CpeDeviceStatus::Online,
        ]);

        // GenieACS has this device but ManagementServer never reported a
        // ConnectionRequestURL at all.
        $this->fakeGenieAcsDevices([
            ['_id' => 'OUI-PC-NOURL', '_lastInform' => now()->toIso8601String()],
        ]);
        $this->fakePing([]);

        $result = app(CpeDeviceStatusSyncService::class)->syncAll();

        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(CpeDeviceStatus::Online, $device->fresh()->status);
    }

    public function test_missing_vantage_nas_skips_every_device_without_erroring(): void
    {
        // No "test-x86-bajastu" NAS row at all.
        $tenant = Tenant::factory()->create();
        CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'genieacs_device_id' => 'OUI-PC-ANY']);

        $this->fakeGenieAcsDevices([$this->genieAcsDevice('OUI-PC-ANY', 'http://10.1.1.5:58000')]);
        $this->fakePing(['10.1.1.5' => true]);

        $result = app(CpeDeviceStatusSyncService::class)->syncAll();

        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['skipped']);
    }
}
