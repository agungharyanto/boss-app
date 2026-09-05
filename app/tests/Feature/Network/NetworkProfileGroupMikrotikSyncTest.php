<?php

namespace Tests\Feature\Network;

use App\Enums\MikrotikSyncStatus;
use App\Enums\NetworkProfileGroupType;
use App\Jobs\PushNetworkProfileGroupToMikrotikJob;
use App\Jobs\RemoveNetworkProfileGroupFromMikrotikJob;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\Tenant;
use App\Services\Network\Contracts\RouterOsGateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * v0.14.3 — RouterOS live-push for NetworkProfileGroup (Grup Profil).
 * Never calls a real router — same anonymous-fake-RouterOsGateway pattern
 * as CustomerIpPoolMikrotikSyncTest (v0.14.2.1). This fake records every
 * call it receives (unlike CustomerIpPoolMikrotikSyncTest's simpler
 * uniform success/message pair) since several tests here need to assert
 * exactly WHICH RouterOS method was invoked with WHICH arguments — Ppp vs
 * Hotspot type route to genuinely different RouterOsGateway methods.
 */
class NetworkProfileGroupMikrotikSyncTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{method: string, args: array}> */
    private array $recordedCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config(['database.connections.radius' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('radius');

        DB::connection('radius')->statement('
            CREATE TABLE radgroupreply (
                id INTEGER PRIMARY KEY,
                groupname TEXT,
                attribute TEXT,
                op TEXT,
                value TEXT
            )
        ');
    }

    /**
     * @param  array{success: bool, message: ?string}  $pppResult
     * @param  array{success: bool, message: ?string}  $hotspotResult
     * @param  array{success: bool, message: ?string}  $pppoeServerResult
     * @param  array{success: bool, message: ?string}  $poolResult
     */
    private function bindGateway(array $pppResult = ['success' => true, 'message' => null], array $hotspotResult = ['success' => true, 'message' => null], array $pppoeServerResult = ['success' => true, 'message' => null], array $poolResult = ['success' => true, 'message' => null]): void
    {
        $recorder = &$this->recordedCalls;

        $this->app->bind(RouterOsGateway::class, function () use ($pppResult, $hotspotResult, $pppoeServerResult, $poolResult, &$recorder) {
            return new class($pppResult, $hotspotResult, $pppoeServerResult, $poolResult, $recorder) implements RouterOsGateway
            {
                public function __construct(
                    private readonly array $pppResult,
                    private readonly array $hotspotResult,
                    private readonly array $pppoeServerResult,
                    private readonly array $poolResult,
                    private array &$recorder,
                ) {}

                public function ping(Nas $nas): array
                {
                    return ['online' => true, 'message' => null];
                }

                public function pingHost(Nas $nas, string $targetIp, int $count = 2): bool
                {
                    return true;
                }

                public function provisionApiUser(Nas $nas, string $a, string $b, string $c, string $d): array
                {
                    return ['success' => true, 'message' => null];
                }

                public function currentWireguardEndpointPort(Nas $nas, string $peerCommentNeedle): ?int
                {
                    return null;
                }

                public function syncIpPool(Nas $nas, string $comment, string $name, string $ranges): array
                {
                    $this->recorder[] = ['method' => 'syncIpPool', 'args' => compact('comment', 'name', 'ranges')];

                    return $this->poolResult;
                }

                public function removeIpPool(Nas $nas, string $comment): array
                {
                    return ['success' => true, 'message' => null];
                }

                public function syncPppProfile(Nas $nas, string $comment, string $name, ?string $remoteAddress, ?string $dnsServer, ?string $parentQueue, ?string $localAddress = null, ?string $rateLimit = null, ?string $sessionTimeout = null): array
                {
                    $this->recorder[] = ['method' => 'syncPppProfile', 'args' => compact('comment', 'name', 'remoteAddress', 'dnsServer', 'parentQueue', 'localAddress')];

                    return $this->pppResult;
                }

                public function removePppProfile(Nas $nas, string $comment): array
                {
                    $this->recorder[] = ['method' => 'removePppProfile', 'args' => compact('comment')];

                    return $this->pppResult;
                }

                public function syncHotspotServerPool(Nas $nas, string $poolName): array
                {
                    $this->recorder[] = ['method' => 'syncHotspotServerPool', 'args' => compact('poolName')];

                    return $this->hotspotResult;
                }

                // v0.14.4 — not exercised by this file's own tests (Grup
                // Profil never calls these directly), present only to
                // satisfy the interface.
                public function syncHotspotUserProfile(Nas $nas, string $lookupName, string $targetName, ?string $rateLimit, int $sharedUsers, ?string $sessionTimeout, ?string $addressPool = null): array
                {
                    return ['success' => true, 'message' => null];
                }

                public function removeHotspotUserProfile(Nas $nas, string $lookupName): array
                {
                    return ['success' => true, 'message' => null];
                }

                public function listInterfaces(Nas $nas): array
                {
                    return [];
                }

                public function syncPppoeServer(Nas $nas, string $comment, string $serviceName, string $interfaceName, string $defaultProfile): array
                {
                    $this->recorder[] = ['method' => 'syncPppoeServer', 'args' => compact('comment', 'serviceName', 'interfaceName', 'defaultProfile')];

                    return $this->pppoeServerResult;
                }

                public function removePppoeServer(Nas $nas, string $comment): array
                {
                    $this->recorder[] = ['method' => 'removePppoeServer', 'args' => compact('comment')];

                    return $this->pppoeServerResult;
                }
            };
        });
    }

    private function group(NetworkProfileGroupType $type = NetworkProfileGroupType::Ppp): NetworkProfileGroup
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create([
            'nas_id' => $nas->id, 'name' => 'Pool-Sync',
            'gateway_ip' => '10.9.9.1', 'range_start' => '10.9.9.10', 'range_end' => '10.9.9.200',
        ]);

        return NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id,
            'customer_ip_pool_id' => $pool->id,
            'type' => $type,
            'dns_primary' => '8.8.8.8',
            'dns_secondary' => '8.8.4.4',
            'parent_queue' => 'my-queue',
        ]);
    }

    // --- Ppp type --------------------------------------------------------

    public function test_push_job_syncs_ppp_profile_with_the_right_arguments_and_marks_synced(): void
    {
        $this->bindGateway();
        $group = $this->group(NetworkProfileGroupType::Ppp);

        $job = new PushNetworkProfileGroupToMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $group->refresh();
        $this->assertSame(MikrotikSyncStatus::Synced, $group->mikrotik_sync_status);
        $this->assertNotNull($group->mikrotik_synced_at);

        // FIX 2 — /ip pool dipastikan ADA dulu, BARU /ppp profile.
        $this->assertSame('syncIpPool', $this->recordedCalls[0]['method']);
        $this->assertSame('Pool-Sync', $this->recordedCalls[0]['args']['name']);
        $this->assertSame('10.9.9.10-10.9.9.200', $this->recordedCalls[0]['args']['ranges']);

        $call = $this->recordedCalls[1];
        $this->assertSame('syncPppProfile', $call['method']);
        $this->assertSame($group->mikrotikComment(), $call['args']['comment']);
        $this->assertSame('Pool-Sync', $call['args']['remoteAddress']);
        // FIX 1 — local-address = gateway_ip pool.
        $this->assertSame('10.9.9.1', $call['args']['localAddress']);
        $this->assertSame('8.8.8.8,8.8.4.4', $call['args']['dnsServer']);
        $this->assertSame('my-queue', $call['args']['parentQueue']);
    }

    public function test_push_job_does_not_push_the_profile_when_the_pool_ensure_fails(): void
    {
        $this->bindGateway(poolResult: ['success' => false, 'message' => 'router unreachable']);
        $group = $this->group(NetworkProfileGroupType::Ppp);

        $job = new PushNetworkProfileGroupToMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 3; // percobaan terakhir → langsung Failed, bukan release+retry
        $job->handle(app(RouterOsGateway::class));

        $group->refresh();
        $this->assertSame(MikrotikSyncStatus::Failed, $group->mikrotik_sync_status);
        $this->assertStringContainsString('IP Pool gagal disinkronkan dulu', $group->mikrotik_sync_error);
        // /ppp profile TIDAK pernah dipush kalau pool-nya sendiri gagal.
        $methods = array_column($this->recordedCalls, 'method');
        $this->assertContains('syncIpPool', $methods);
        $this->assertNotContains('syncPppProfile', $methods);
        // Pool ikut ditandai gagal.
        $this->assertSame(MikrotikSyncStatus::Failed, $group->customerIpPool->fresh()->mikrotik_sync_status);
    }

    public function test_pool_that_drifted_away_is_recreated_by_the_group_resync_then_the_profile_succeeds(): void
    {
        // Skenario nyata Agung: admin hapus /ip pool + /ppp profile manual
        // di router. BOSS App masih menyimpan datanya. "Sync Ulang" harus
        // membangun ulang keduanya, urutan benar, tidak gagal permanen.
        $this->bindGateway(); // fake: syncIpPool sukses (re-create), syncPppProfile sukses
        $group = $this->group(NetworkProfileGroupType::Ppp);
        $group->customerIpPool->markSyncFailed('drift — dihapus manual di router');

        $job = new PushNetworkProfileGroupToMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $group->refresh();
        $this->assertSame(MikrotikSyncStatus::Synced, $group->mikrotik_sync_status);
        // Status pool yang tadinya "failed"/basi ikut diperbaiki jadi synced.
        $this->assertSame(MikrotikSyncStatus::Synced, $group->customerIpPool->fresh()->mikrotik_sync_status);
        $this->assertSame(['syncIpPool', 'syncPppProfile'], array_column($this->recordedCalls, 'method'));
    }

    public function test_push_job_omits_dns_server_argument_when_both_dns_fields_are_null(): void
    {
        $this->bindGateway();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'type' => NetworkProfileGroupType::Ppp,
            'dns_primary' => null, 'dns_secondary' => null,
        ]);

        $job = new PushNetworkProfileGroupToMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        // [0] = syncIpPool (FIX 2), [1] = syncPppProfile.
        $this->assertNull($this->recordedCalls[1]['args']['dnsServer']);
    }

    public function test_remove_job_removes_ppp_profile(): void
    {
        $this->bindGateway();
        $group = $this->group(NetworkProfileGroupType::Ppp);
        $group->delete();

        $job = new RemoveNetworkProfileGroupFromMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame('removePppProfile', $this->recordedCalls[0]['method']);
        $this->assertSame($group->mikrotikComment(), $this->recordedCalls[0]['args']['comment']);
    }

    // --- Revisi Grup Profil: PPPoE Server push/remove --------------------

    public function test_push_job_also_syncs_pppoe_server_when_interface_and_service_name_are_set(): void
    {
        $this->bindGateway();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Pool-Sync']);
        $group = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id,
            'customer_ip_pool_id' => $pool->id,
            'type' => NetworkProfileGroupType::Ppp,
            'name' => 'HomeFixed-10Mbps-Test',
            'interface_name' => 'vlan110-PPPoE-10Mbps',
            'service_name' => 'PPPoE-Vlan110-10Mbps',
        ]);

        $job = new PushNetworkProfileGroupToMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $group->refresh();
        $this->assertSame(MikrotikSyncStatus::Synced, $group->mikrotik_sync_status);

        // [0] = syncIpPool (FIX 2), [1] = syncPppProfile, [2] = syncPppoeServer.
        $this->assertSame('syncIpPool', $this->recordedCalls[0]['method']);
        $this->assertSame('syncPppProfile', $this->recordedCalls[1]['method']);
        $this->assertSame('syncPppoeServer', $this->recordedCalls[2]['method']);
        $call = $this->recordedCalls[2]['args'];
        $this->assertSame($group->mikrotikComment(), $call['comment']);
        $this->assertSame('PPPoE-Vlan110-10Mbps', $call['serviceName']);
        $this->assertSame('vlan110-PPPoE-10Mbps', $call['interfaceName']);
        $this->assertSame('HomeFixed-10Mbps-Test', $call['defaultProfile']);
    }

    public function test_push_job_skips_pppoe_server_sync_when_interface_name_is_null(): void
    {
        $this->bindGateway();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id,
            'customer_ip_pool_id' => $pool->id,
            'type' => NetworkProfileGroupType::Ppp,
            'interface_name' => null,
            'service_name' => 'PPPoE-Vlan110-10Mbps',
        ]);

        $job = new PushNetworkProfileGroupToMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $group->refresh();
        $this->assertSame(MikrotikSyncStatus::Synced, $group->mikrotik_sync_status);
        $this->assertCount(2, $this->recordedCalls);
        $this->assertSame('syncIpPool', $this->recordedCalls[0]['method']);
        $this->assertSame('syncPppProfile', $this->recordedCalls[1]['method']);
    }

    public function test_push_job_skips_pppoe_server_sync_when_service_name_is_null(): void
    {
        $this->bindGateway();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id,
            'customer_ip_pool_id' => $pool->id,
            'type' => NetworkProfileGroupType::Ppp,
            'interface_name' => 'vlan110-PPPoE-10Mbps',
            'service_name' => null,
        ]);

        $job = new PushNetworkProfileGroupToMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $group->refresh();
        $this->assertSame(MikrotikSyncStatus::Synced, $group->mikrotik_sync_status);
        $this->assertCount(2, $this->recordedCalls);
        $this->assertSame('syncIpPool', $this->recordedCalls[0]['method']);
        $this->assertSame('syncPppProfile', $this->recordedCalls[1]['method']);
    }

    public function test_push_job_marks_failed_with_combined_message_when_pppoe_server_sync_fails_on_final_attempt(): void
    {
        $this->bindGateway(pppoeServerResult: ['success' => false, 'message' => 'interface not found']);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id,
            'customer_ip_pool_id' => $pool->id,
            'type' => NetworkProfileGroupType::Ppp,
            'interface_name' => 'vlan110-PPPoE-10Mbps',
            'service_name' => 'PPPoE-Vlan110-10Mbps',
        ]);

        $job = new PushNetworkProfileGroupToMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 3;
        $job->handle(app(RouterOsGateway::class));

        $job->assertNotReleased();
        $group->refresh();
        $this->assertSame(MikrotikSyncStatus::Failed, $group->mikrotik_sync_status);
        $this->assertStringContainsString('/ppp profile berhasil, tapi PPPoE Server gagal', $group->mikrotik_sync_error);
        $this->assertStringContainsString('interface not found', $group->mikrotik_sync_error);
    }

    public function test_remove_job_also_removes_pppoe_server_when_interface_and_service_name_are_set(): void
    {
        $this->bindGateway();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id,
            'customer_ip_pool_id' => $pool->id,
            'type' => NetworkProfileGroupType::Ppp,
            'interface_name' => 'vlan110-PPPoE-10Mbps',
            'service_name' => 'PPPoE-Vlan110-10Mbps',
        ]);
        $group->delete();

        $job = new RemoveNetworkProfileGroupFromMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame('removePppProfile', $this->recordedCalls[0]['method']);
        $this->assertSame('removePppoeServer', $this->recordedCalls[1]['method']);
        $this->assertSame($group->mikrotikComment(), $this->recordedCalls[1]['args']['comment']);
    }

    public function test_remove_job_skips_pppoe_server_removal_when_fields_were_never_set(): void
    {
        $this->bindGateway();
        $group = $this->group(NetworkProfileGroupType::Ppp);
        $group->delete();

        $job = new RemoveNetworkProfileGroupFromMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertCount(1, $this->recordedCalls);
        $this->assertSame('removePppProfile', $this->recordedCalls[0]['method']);
    }

    // --- Hotspot type — the real architectural finding this sprint ------

    public function test_push_job_refuses_hotspot_type_immediately_when_nas_has_no_hotspot_server(): void
    {
        $this->bindGateway(hotspotResult: ['success' => false, 'message' => 'NAS ini belum punya Hotspot Server di Mikrotik. Buat Hotspot Server terlebih dahulu (System > Hotspot Setup) sebelum push Grup Profil tipe Hotspot.']);
        $group = $this->group(NetworkProfileGroupType::Hotspot);

        $job = new PushNetworkProfileGroupToMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        // Immediately Failed, NOT released for retry — a missing Hotspot
        // Server is a permanent config problem, retrying can't fix it.
        $job->assertNotReleased();
        $group->refresh();
        $this->assertSame(MikrotikSyncStatus::Failed, $group->mikrotik_sync_status);
        $this->assertStringContainsString('belum punya Hotspot Server', $group->mikrotik_sync_error);
    }

    public function test_push_job_syncs_hotspot_server_pool_when_a_server_already_exists(): void
    {
        $this->bindGateway(hotspotResult: ['success' => true, 'message' => null]);
        $group = $this->group(NetworkProfileGroupType::Hotspot);

        $job = new PushNetworkProfileGroupToMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $group->refresh();
        $this->assertSame(MikrotikSyncStatus::Synced, $group->mikrotik_sync_status);
        $this->assertSame('syncHotspotServerPool', $this->recordedCalls[0]['method']);
        $this->assertSame('Pool-Sync', $this->recordedCalls[0]['args']['poolName']);
    }

    public function test_remove_job_does_nothing_to_the_router_for_hotspot_type(): void
    {
        $this->bindGateway();
        $group = $this->group(NetworkProfileGroupType::Hotspot);
        $group->delete();

        $job = new RemoveNetworkProfileGroupFromMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame([], $this->recordedCalls);
    }

    public function test_push_job_releases_with_backoff_on_a_transient_ppp_failure(): void
    {
        $this->bindGateway(pppResult: ['success' => false, 'message' => 'connection timed out']);
        $group = $this->group(NetworkProfileGroupType::Ppp);

        $job = new PushNetworkProfileGroupToMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 1;
        $job->handle(app(RouterOsGateway::class));

        $job->assertReleased(delay: 30);
        $this->assertSame(MikrotikSyncStatus::Pending, $group->fresh()->mikrotik_sync_status);
    }

    public function test_push_job_marks_failed_on_the_final_attempt(): void
    {
        $this->bindGateway(pppResult: ['success' => false, 'message' => 'connection timed out']);
        $group = $this->group(NetworkProfileGroupType::Ppp);

        $job = new PushNetworkProfileGroupToMikrotikJob($group->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 3;
        $job->handle(app(RouterOsGateway::class));

        $job->assertNotReleased();
        $this->assertSame(MikrotikSyncStatus::Failed, $group->fresh()->mikrotik_sync_status);
    }

    public function test_push_job_skips_gracefully_when_the_group_no_longer_exists(): void
    {
        $this->bindGateway();

        $job = new PushNetworkProfileGroupToMikrotikJob(999999);
        $job->withFakeQueueInteractions();

        $job->handle(app(RouterOsGateway::class));
        $job->assertNotReleased();
    }
}
