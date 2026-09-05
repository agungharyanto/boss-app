<?php

namespace Tests\Feature\Network;

use App\Enums\MikrotikSyncStatus;
use App\Enums\NetworkProfileGroupType;
use App\Jobs\PushPppPackageToMikrotikJob;
use App\Jobs\RemovePppPackageFromMikrotikJob;
use App\Models\BandwidthProfile;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Tenant;
use App\Services\Network\Contracts\RouterOsGateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.14.5.4 (ATURAN KERAS 1:1 Grup Profil <-> Profil PPP) — a Profil PPP no
 * longer pushes its OWN `/ppp profile`. Both PushPppPackageToMikrotikJob and
 * PushNetworkProfileGroupToMikrotikJob run through PppProfileSyncService,
 * converging on ONE router object keyed by the Grup Profil's comment. This
 * job additionally marks the PACKAGE synced/failed and RESETS the profile
 * to bare (no rate-limit) when the package is deactivated/removed.
 *
 * Never calls a real router — same anonymous-fake-RouterOsGateway recorder
 * pattern as NetworkProfileGroupMikrotikSyncTest / PppProfileSyncServiceTest.
 */
class PppPackageMikrotikSyncTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{method: string, args: array}> */
    private array $recordedCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @param  array{success: bool, message: ?string}  $profileResult
     * @param  array{success: bool, message: ?string}  $poolResult
     */
    private function bindGateway(array $profileResult = ['success' => true, 'message' => null], array $poolResult = ['success' => true, 'message' => null]): void
    {
        $recorder = &$this->recordedCalls;

        $this->app->bind(RouterOsGateway::class, function () use ($profileResult, $poolResult, &$recorder) {
            return new class($profileResult, $poolResult, $recorder) implements RouterOsGateway
            {
                public function __construct(
                    private readonly array $profileResult,
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
                    $this->recorder[] = ['method' => 'syncPppProfile', 'args' => compact('comment', 'name', 'remoteAddress', 'dnsServer', 'parentQueue', 'localAddress', 'rateLimit', 'sessionTimeout')];

                    return $this->profileResult;
                }

                public function removePppProfile(Nas $nas, string $comment): array
                {
                    $this->recorder[] = ['method' => 'removePppProfile', 'args' => compact('comment')];

                    return ['success' => true, 'message' => null];
                }

                public function syncHotspotServerPool(Nas $nas, string $poolName): array
                {
                    return ['success' => true, 'message' => null];
                }

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

                    return ['success' => true, 'message' => null];
                }

                public function removePppoeServer(Nas $nas, string $comment): array
                {
                    return ['success' => true, 'message' => null];
                }
            };
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $groupOverrides
     */
    private function package(array $overrides = [], array $groupOverrides = []): PppPackage
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create([
            'nas_id' => $nas->id, 'name' => 'Ppp-Pool-Sync',
            'gateway_ip' => '10.7.7.1', 'range_start' => '10.7.7.10', 'range_end' => '10.7.7.200',
        ]);
        $group = NetworkProfileGroup::factory()->create(array_merge([
            'nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'type' => NetworkProfileGroupType::Ppp,
            'name' => 'Grup-PPP-Sync', 'dns_primary' => '8.8.8.8', 'dns_secondary' => '8.8.4.4', 'parent_queue' => 'my-queue',
        ], $groupOverrides));
        $bandwidth = BandwidthProfile::factory()->create([
            'tenant_id' => $tenant->id, 'upload_max' => 5000, 'download_max' => 10000,
        ]);

        return PppPackage::factory()->create(array_merge([
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => $bandwidth->id,
            'shared_users' => 2,
        ], $overrides));
    }

    public function test_push_job_updates_the_grup_profils_shared_profile_with_the_packages_rate_limit_and_session_timeout(): void
    {
        $this->bindGateway();
        $package = $this->package(['name' => 'Paket-PPP-A', 'active_duration_value' => 1, 'active_duration_unit' => 'month']);
        $group = $package->networkProfileGroup;

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $package->refresh();
        $group->refresh();
        // BOTH the package AND its Grup Profil are marked synced.
        $this->assertSame(MikrotikSyncStatus::Synced, $package->mikrotik_sync_status);
        $this->assertSame(MikrotikSyncStatus::Synced, $group->mikrotik_sync_status);

        // /ip pool ensured first, THEN the shared /ppp profile, THEN the
        // legacy per-package object is swept (idempotent no-op here).
        $this->assertSame(['syncIpPool', 'syncPppProfile', 'removePppProfile'], array_column($this->recordedCalls, 'method'));
        // The legacy sweep targets the PACKAGE's own comment.
        $this->assertSame($package->mikrotikComment(), $this->recordedCalls[2]['args']['comment']);
        $this->assertSame('Ppp-Pool-Sync', $this->recordedCalls[0]['args']['name']);
        $this->assertSame('10.7.7.10-10.7.7.200', $this->recordedCalls[0]['args']['ranges']);

        $call = $this->recordedCalls[1]['args'];
        // Keyed by the GRUP PROFIL's comment/name — NOT the package's.
        $this->assertSame($group->mikrotikComment(), $call['comment']);
        $this->assertSame('Grup-PPP-Sync', $call['name']);
        $this->assertSame('Ppp-Pool-Sync', $call['remoteAddress']);
        $this->assertSame('8.8.8.8,8.8.4.4', $call['dnsServer']);
        $this->assertSame('my-queue', $call['parentQueue']);
        $this->assertSame('10.7.7.1', $call['localAddress']);
        // DARURAT 2026-09-06 — rate-limit sekarang format POLOS (rx/tx saja),
        // tanpa grup burst (`1s/1s` di slot burst-time bikin RouterOS gagal
        // buat /queue simple dinamis saat sesi PPPoE connect → memutus sesi
        // pelanggan). Lihat RouterOsQueuePriority's own docblock.
        $this->assertSame('5000k/10000k', $call['rateLimit']);
        $this->assertSame('30d', $call['sessionTimeout']);
    }

    public function test_priority_is_stored_but_not_pushed_via_rate_limit_after_the_burst_string_broke_pppoe(): void
    {
        $this->bindGateway();
        $package = $this->package(['name' => 'Paket-Prioritas', 'priority' => 3]);

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        // Priority 3 tersimpan di DB tapi TIDAK muncul di string rate-limit
        // (tidak ada grup burst untuk menampung slot priority ke-5).
        $this->assertSame(3, $package->fresh()->priority);
        $this->assertSame('5000k/10000k', $this->recordedCalls[1]['args']['rateLimit']);
    }

    public function test_push_job_sends_null_session_timeout_for_an_unlimited_duration_package(): void
    {
        $this->bindGateway();
        $package = $this->package(['active_duration_value' => 0, 'active_duration_unit' => 'month']);

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame(MikrotikSyncStatus::Synced, $package->fresh()->mikrotik_sync_status);
        $this->assertNull($this->recordedCalls[1]['args']['sessionTimeout']);
    }

    public function test_push_job_for_a_deactivated_package_resets_the_shared_profile_to_bare(): void
    {
        $this->bindGateway();
        $package = $this->package(['is_active' => false]);

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        // rate-limit/session-timeout passed as null -> gateway SET branch
        // clears them on the router.
        $this->assertNull($this->recordedCalls[1]['args']['rateLimit']);
        $this->assertNull($this->recordedCalls[1]['args']['sessionTimeout']);
        $this->assertSame(MikrotikSyncStatus::Synced, $package->fresh()->mikrotik_sync_status);
    }

    public function test_push_job_pushes_a_pppoe_server_when_the_group_has_interface_and_service_name(): void
    {
        $this->bindGateway();
        $package = $this->package([], ['interface_name' => 'vlan10-PPPoE', 'service_name' => 'svc-ppp']);
        $group = $package->networkProfileGroup;

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame(['syncIpPool', 'syncPppProfile', 'syncPppoeServer', 'removePppProfile'], array_column($this->recordedCalls, 'method'));
        $call = $this->recordedCalls[2]['args'];
        $this->assertSame('svc-ppp', $call['serviceName']);
        $this->assertSame('vlan10-PPPoE', $call['interfaceName']);
        $this->assertSame($group->name, $call['defaultProfile']);
    }

    public function test_push_job_reflects_a_live_update_to_the_parent_groups_pool_not_a_stale_snapshot(): void
    {
        $this->bindGateway();
        $package = $this->package();
        $group = $package->networkProfileGroup;
        $newPool = CustomerIpPool::factory()->create(['nas_id' => $group->nas_id, 'name' => 'Ppp-Pool-Baru', 'gateway_ip' => '10.9.9.1']);
        $group->update(['customer_ip_pool_id' => $newPool->id]);

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame('Ppp-Pool-Baru', $this->recordedCalls[1]['args']['remoteAddress']);
        $this->assertSame('10.9.9.1', $this->recordedCalls[1]['args']['localAddress']);
    }

    public function test_pool_name_that_collides_with_a_ppp_profile_name_gets_differentiated(): void
    {
        $this->bindGateway();
        // Pool name == the parent Grup Profil name (both "Kembar-PPP").
        $package = $this->package([], ['name' => 'Kembar-PPP']);
        $group = $package->networkProfileGroup;
        $group->customerIpPool->update(['name' => 'Kembar-PPP']);

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame('Kembar-PPP (pool)', $this->recordedCalls[0]['args']['name']);
        $this->assertSame('Kembar-PPP (pool)', $this->recordedCalls[1]['args']['remoteAddress']);
        // The /ppp profile name itself stays the Grup Profil name, untouched.
        $this->assertSame('Kembar-PPP', $this->recordedCalls[1]['args']['name']);
    }

    public function test_push_job_does_not_push_the_profile_when_the_pool_ensure_fails(): void
    {
        $this->bindGateway(poolResult: ['success' => false, 'message' => 'router unreachable']);
        $package = $this->package();
        $group = $package->networkProfileGroup;

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 3;
        $job->handle(app(RouterOsGateway::class));

        $package->refresh();
        $group->refresh();
        $this->assertSame(MikrotikSyncStatus::Failed, $package->mikrotik_sync_status);
        $this->assertSame(MikrotikSyncStatus::Failed, $group->mikrotik_sync_status);
        $this->assertStringContainsString('IP Pool gagal disinkronkan dulu', (string) $package->mikrotik_sync_error);
        $this->assertSame(['syncIpPool'], array_column($this->recordedCalls, 'method'));
        $this->assertSame(MikrotikSyncStatus::Failed, $group->customerIpPool->fresh()->mikrotik_sync_status);
    }

    public function test_pool_that_drifted_away_is_recreated_by_the_resync_then_the_profile_succeeds(): void
    {
        $this->bindGateway();
        $package = $this->package();
        $package->networkProfileGroup->customerIpPool->markSyncFailed('drift: dihapus manual di router');

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame(['syncIpPool', 'syncPppProfile', 'removePppProfile'], array_column($this->recordedCalls, 'method'));
        $this->assertSame(MikrotikSyncStatus::Synced, $package->fresh()->mikrotik_sync_status);
        $this->assertSame(MikrotikSyncStatus::Synced, $package->networkProfileGroup->customerIpPool->fresh()->mikrotik_sync_status);
    }

    public function test_remove_job_resets_the_grup_profils_shared_profile_to_bare_and_sweeps_the_legacy_object(): void
    {
        $this->bindGateway();
        $package = $this->package(['name' => 'Paket-Hapus']);
        $group = $package->networkProfileGroup;
        $package->delete();

        $job = new RemovePppPackageFromMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        // Legacy per-package object swept FIRST, then the shared object is
        // re-pushed bare (rate-limit/session-timeout cleared).
        $this->assertSame(['removePppProfile', 'syncIpPool', 'syncPppProfile'], array_column($this->recordedCalls, 'method'));
        $this->assertSame($package->mikrotikComment(), $this->recordedCalls[0]['args']['comment']);
        $call = $this->recordedCalls[2]['args'];
        $this->assertSame($group->mikrotikComment(), $call['comment']);
        $this->assertNull($call['rateLimit']);
        $this->assertNull($call['sessionTimeout']);
        $this->assertSame(MikrotikSyncStatus::Synced, $group->fresh()->mikrotik_sync_status);
    }

    public function test_push_job_releases_with_backoff_on_a_transient_profile_failure(): void
    {
        $this->bindGateway(['success' => false, 'message' => 'connection timed out']);
        $package = $this->package();

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 1;
        $job->handle(app(RouterOsGateway::class));

        $job->assertReleased(delay: 30);
        $this->assertSame(MikrotikSyncStatus::Pending, $package->fresh()->mikrotik_sync_status);
    }

    public function test_push_job_marks_failed_on_the_final_attempt(): void
    {
        $this->bindGateway(['success' => false, 'message' => 'connection timed out']);
        $package = $this->package();
        $group = $package->networkProfileGroup;

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 3;
        $job->handle(app(RouterOsGateway::class));

        $job->assertNotReleased();
        $this->assertSame(MikrotikSyncStatus::Failed, $package->fresh()->mikrotik_sync_status);
        $this->assertSame(MikrotikSyncStatus::Failed, $group->fresh()->mikrotik_sync_status);
    }

    public function test_push_job_skips_gracefully_when_the_package_no_longer_exists(): void
    {
        $this->bindGateway();

        $job = new PushPppPackageToMikrotikJob(999999);
        $job->withFakeQueueInteractions();

        $job->handle(app(RouterOsGateway::class));
        $job->assertNotReleased();
        $this->assertSame([], $this->recordedCalls);
    }

    public function test_remove_job_skips_gracefully_when_the_package_no_longer_exists(): void
    {
        $this->bindGateway();

        $job = new RemovePppPackageFromMikrotikJob(999999);
        $job->withFakeQueueInteractions();

        $job->handle(app(RouterOsGateway::class));
        $job->assertNotReleased();
        $this->assertSame([], $this->recordedCalls);
    }
}
