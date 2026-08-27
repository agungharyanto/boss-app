<?php

namespace Tests\Feature\Network;

use App\Enums\MikrotikSyncStatus;
use App\Enums\NetworkProfileGroupType;
use App\Jobs\PushHotspotPackageToMikrotikJob;
use App\Jobs\RemoveHotspotPackageFromMikrotikJob;
use App\Models\BandwidthProfile;
use App\Models\CustomerIpPool;
use App\Models\HotspotPackage;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\Tenant;
use App\Services\Network\Contracts\RouterOsGateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.14.4 — RouterOS live-push for HotspotPackage (Profil Hotspot). Never
 * calls a real router — same anonymous-fake-RouterOsGateway recorder
 * pattern as NetworkProfileGroupMikrotikSyncTest (v0.14.3). Real behavior
 * of `/ip hotspot user profile` (no `comment` support, rate-limit/
 * session-timeout format) was verified empirically against
 * ro-hotspot.bajastu.id before writing this file — see RouterOsGateway's
 * own docblock and CLAUDE.md's v0.14.4 investigation section.
 */
class HotspotPackageMikrotikSyncTest extends TestCase
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

                public function syncPppProfile(Nas $nas, string $comment, string $name, string $remoteAddress, ?string $dnsServer, ?string $parentQueue): array
                {
                    return ['success' => true, 'message' => null];
                }

                public function removePppProfile(Nas $nas, string $comment): array
                {
                    return ['success' => true, 'message' => null];
                }

                public function syncHotspotServerPool(Nas $nas, string $poolName): array
                {
                    return ['success' => true, 'message' => null];
                }

                public function syncHotspotUserProfile(Nas $nas, string $lookupName, string $targetName, ?string $rateLimit, int $sharedUsers, ?string $sessionTimeout): array
                {
                    $this->recorder[] = ['method' => 'syncHotspotUserProfile', 'args' => compact('lookupName', 'targetName', 'rateLimit', 'sharedUsers', 'sessionTimeout')];

                    return $this->result;
                }

                public function removeHotspotUserProfile(Nas $nas, string $lookupName): array
                {
                    $this->recorder[] = ['method' => 'removeHotspotUserProfile', 'args' => compact('lookupName')];

                    return $this->result;
                }
            };
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function package(array $overrides = []): HotspotPackage
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'type' => NetworkProfileGroupType::Hotspot,
        ]);
        $bandwidth = BandwidthProfile::factory()->create([
            'tenant_id' => $tenant->id, 'upload_max' => 5000, 'download_max' => 10000,
        ]);

        return HotspotPackage::factory()->create(array_merge([
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => $bandwidth->id,
            'shared_users' => 2,
        ], $overrides));
    }

    public function test_push_job_syncs_unlimited_package_with_rate_limit_and_no_session_timeout(): void
    {
        $this->bindGateway();
        $package = $this->package(['name' => 'Paket-A']);

        $job = new PushHotspotPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $package->refresh();
        $this->assertSame(MikrotikSyncStatus::Synced, $package->mikrotik_sync_status);
        $this->assertNotNull($package->mikrotik_synced_at);
        $this->assertSame('Paket-A', $package->mikrotik_profile_name);

        $call = $this->recordedCalls[0];
        $this->assertSame('syncHotspotUserProfile', $call['method']);
        $this->assertSame('Paket-A', $call['args']['lookupName']);
        $this->assertSame('Paket-A', $call['args']['targetName']);
        $this->assertSame('5000k/10000k', $call['args']['rateLimit']);
        $this->assertSame(2, $call['args']['sharedUsers']);
        $this->assertNull($call['args']['sessionTimeout']);
    }

    public function test_push_job_includes_session_timeout_for_limited_time_base_package(): void
    {
        $this->bindGateway();
        $package = $this->package([
            'profile_type' => 'limited', 'limit_type' => 'time_base',
            'active_duration_value' => 2, 'active_duration_unit' => 'day',
        ]);

        $job = new PushHotspotPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame('2d', $this->recordedCalls[0]['args']['sessionTimeout']);
    }

    public function test_push_job_converts_month_duration_to_30_days_per_month(): void
    {
        $this->bindGateway();
        $package = $this->package([
            'profile_type' => 'limited', 'limit_type' => 'time_base',
            'active_duration_value' => 1, 'active_duration_unit' => 'month',
        ]);

        $job = new PushHotspotPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame('30d', $this->recordedCalls[0]['args']['sessionTimeout']);
    }

    /**
     * QuotaBase has no RouterOS profile-level field to push to — see
     * HotspotLimitType's own docblock. session-timeout must stay omitted
     * even though profile_type=Limited.
     */
    public function test_push_job_omits_session_timeout_for_limited_quota_base_package(): void
    {
        $this->bindGateway();
        $package = $this->package([
            'profile_type' => 'limited', 'limit_type' => 'quota_base',
            'active_duration_value' => 30, 'active_duration_unit' => 'day',
        ]);

        $job = new PushHotspotPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertNull($this->recordedCalls[0]['args']['sessionTimeout']);
    }

    /**
     * The real reason mikrotik_profile_name exists — see the migration's
     * own docblock. A package that was already synced once under an OLDER
     * name must be looked up by that OLD name (so the router-side object
     * gets renamed in place), while the value actually SET is always the
     * CURRENT name.
     */
    public function test_push_job_looks_up_by_the_previously_synced_name_after_a_rename(): void
    {
        $this->bindGateway();
        $package = $this->package(['name' => 'Nama-Baru', 'mikrotik_profile_name' => 'Nama-Lama']);

        $job = new PushHotspotPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame('Nama-Lama', $this->recordedCalls[0]['args']['lookupName']);
        $this->assertSame('Nama-Baru', $this->recordedCalls[0]['args']['targetName']);
        // A successful sync updates mikrotik_profile_name to the new
        // current name, so the NEXT push (if renamed again) looks up by
        // THIS name, not the original one.
        $this->assertSame('Nama-Baru', $package->fresh()->mikrotik_profile_name);
    }

    public function test_push_job_falls_back_to_current_name_when_never_synced_before(): void
    {
        $this->bindGateway();
        $package = $this->package(['name' => 'Paket-Baru', 'mikrotik_profile_name' => null]);

        $job = new PushHotspotPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame('Paket-Baru', $this->recordedCalls[0]['args']['lookupName']);
    }

    public function test_remove_job_removes_hotspot_user_profile_by_lookup_name(): void
    {
        $this->bindGateway();
        $package = $this->package(['name' => 'Paket-Hapus', 'mikrotik_profile_name' => 'Paket-Hapus']);
        $package->delete();

        $job = new RemoveHotspotPackageFromMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame('removeHotspotUserProfile', $this->recordedCalls[0]['method']);
        $this->assertSame('Paket-Hapus', $this->recordedCalls[0]['args']['lookupName']);
    }

    public function test_push_job_refuses_immediately_when_nas_has_no_hotspot_server(): void
    {
        $this->bindGateway(['success' => false, 'message' => 'NAS ini belum punya Hotspot Server di Mikrotik. Buat Hotspot Server terlebih dahulu (System > Hotspot Setup) sebelum push Profil Hotspot.']);
        $package = $this->package();

        $job = new PushHotspotPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $job->assertNotReleased();
        $package->refresh();
        $this->assertSame(MikrotikSyncStatus::Failed, $package->mikrotik_sync_status);
        $this->assertStringContainsString('belum punya Hotspot Server', $package->mikrotik_sync_error);
    }

    public function test_push_job_releases_with_backoff_on_a_transient_failure(): void
    {
        $this->bindGateway(['success' => false, 'message' => 'connection timed out']);
        $package = $this->package();

        $job = new PushHotspotPackageToMikrotikJob($package->id);
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

        $job = new PushHotspotPackageToMikrotikJob($package->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 3;
        $job->handle(app(RouterOsGateway::class));

        $job->assertNotReleased();
        $this->assertSame(MikrotikSyncStatus::Failed, $package->fresh()->mikrotik_sync_status);
    }

    public function test_push_job_skips_gracefully_when_the_package_no_longer_exists(): void
    {
        $this->bindGateway();

        $job = new PushHotspotPackageToMikrotikJob(999999);
        $job->withFakeQueueInteractions();

        $job->handle(app(RouterOsGateway::class));
        $job->assertNotReleased();
    }

    public function test_remove_job_skips_gracefully_when_the_package_no_longer_exists(): void
    {
        $this->bindGateway();

        $job = new RemoveHotspotPackageFromMikrotikJob(999999);
        $job->withFakeQueueInteractions();

        $job->handle(app(RouterOsGateway::class));
        $job->assertNotReleased();
        $this->assertSame([], $this->recordedCalls);
    }
}
