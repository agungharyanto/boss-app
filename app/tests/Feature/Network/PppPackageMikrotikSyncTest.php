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
 * v0.14.5 — RouterOS live-push for PppPackage (Profil PPP). Never calls a
 * real router — same anonymous-fake-RouterOsGateway recorder pattern as
 * HotspotPackageMikrotikSyncTest/NetworkProfileGroupMikrotikSyncTest. Real
 * `/ppp profile` rate-limit/session-timeout formats verified empirically
 * against ro-hotspot.bajastu.id before writing this file — see
 * RouterOsGateway::syncPppProfile()'s own docblock.
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
     * @param  array{success: bool, message: ?string}  $result
     */
    private function bindGateway(array $result = ['success' => true, 'message' => null]): void
    {
        $recorder = &$this->recordedCalls;

        $this->app->bind(RouterOsGateway::class, function () use ($result, &$recorder) {
            return new class($result, $recorder) implements RouterOsGateway
            {
                public function __construct(
                    private readonly array $result,
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
                    return ['success' => true, 'message' => null];
                }

                public function removeIpPool(Nas $nas, string $comment): array
                {
                    return ['success' => true, 'message' => null];
                }

                public function syncPppProfile(Nas $nas, string $comment, string $name, ?string $remoteAddress, ?string $dnsServer, ?string $parentQueue, ?string $localAddress = null, ?string $rateLimit = null, ?string $sessionTimeout = null): array
                {
                    $this->recorder[] = ['method' => 'syncPppProfile', 'args' => compact('comment', 'name', 'remoteAddress', 'dnsServer', 'parentQueue', 'localAddress', 'rateLimit', 'sessionTimeout')];

                    return $this->result;
                }

                public function removePppProfile(Nas $nas, string $comment): array
                {
                    $this->recorder[] = ['method' => 'removePppProfile', 'args' => compact('comment')];

                    return $this->result;
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
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Ppp-Pool-Sync']);
        $group = NetworkProfileGroup::factory()->create(array_merge([
            'nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'type' => NetworkProfileGroupType::Ppp,
            'dns_primary' => '8.8.8.8', 'dns_secondary' => '8.8.4.4', 'parent_queue' => 'my-queue',
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

    public function test_push_job_syncs_profile_with_inherited_pool_dns_parent_queue_and_own_rate_limit_session_timeout(): void
    {
        $this->bindGateway();
        $package = $this->package(['name' => 'Paket-PPP-A', 'active_duration_value' => 1, 'active_duration_unit' => 'month']);

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $package->refresh();
        $this->assertSame(MikrotikSyncStatus::Synced, $package->mikrotik_sync_status);
        $this->assertNotNull($package->mikrotik_synced_at);

        $call = $this->recordedCalls[0];
        $this->assertSame('syncPppProfile', $call['method']);
        $this->assertSame($package->mikrotikComment(), $call['args']['comment']);
        $this->assertSame('Paket-PPP-A', $call['args']['name']);
        // Inherited live from the parent Grup Profil, not copied/cached.
        $this->assertSame('Ppp-Pool-Sync', $call['args']['remoteAddress']);
        $this->assertSame('8.8.8.8,8.8.4.4', $call['args']['dnsServer']);
        $this->assertSame('my-queue', $call['args']['parentQueue']);
        $this->assertNull($call['args']['localAddress']);
        // Own rate-limit (Bandwidth Profile) and session-timeout (Masa Aktif).
        $this->assertSame('5000k/10000k', $call['args']['rateLimit']);
        $this->assertSame('30d', $call['args']['sessionTimeout']);
    }

    public function test_push_job_omits_dns_server_when_group_has_no_dns_configured(): void
    {
        $this->bindGateway();
        $package = $this->package([], ['dns_primary' => null, 'dns_secondary' => null]);

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertNull($this->recordedCalls[0]['args']['dnsServer']);
    }

    public function test_push_job_converts_duration_units_correctly(): void
    {
        $this->bindGateway();
        $package = $this->package(['active_duration_value' => 2, 'active_duration_unit' => 'day']);

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame('2d', $this->recordedCalls[0]['args']['sessionTimeout']);
    }

    public function test_push_job_reflects_a_live_update_to_the_parent_groups_pool_not_a_stale_snapshot(): void
    {
        $this->bindGateway();
        $package = $this->package();
        $group = $package->networkProfileGroup;
        $newPool = CustomerIpPool::factory()->create(['nas_id' => $group->nas_id, 'name' => 'Ppp-Pool-Baru']);
        $group->update(['customer_ip_pool_id' => $newPool->id]);

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame('Ppp-Pool-Baru', $this->recordedCalls[0]['args']['remoteAddress']);
    }

    public function test_remove_job_removes_ppp_profile_by_comment(): void
    {
        $this->bindGateway();
        $package = $this->package(['name' => 'Paket-Hapus']);
        $comment = $package->mikrotikComment();
        $package->delete();

        $job = new RemovePppPackageFromMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame('removePppProfile', $this->recordedCalls[0]['method']);
        $this->assertSame($comment, $this->recordedCalls[0]['args']['comment']);
    }

    public function test_push_job_releases_with_backoff_on_a_transient_failure(): void
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

        $job = new PushPppPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 3;
        $job->handle(app(RouterOsGateway::class));

        $job->assertNotReleased();
        $this->assertSame(MikrotikSyncStatus::Failed, $package->fresh()->mikrotik_sync_status);
    }

    public function test_push_job_skips_gracefully_when_the_package_no_longer_exists(): void
    {
        $this->bindGateway();

        $job = new PushPppPackageToMikrotikJob(999999);
        $job->withFakeQueueInteractions();

        $job->handle(app(RouterOsGateway::class));
        $job->assertNotReleased();
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
