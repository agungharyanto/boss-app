<?php

namespace Tests\Feature\Network;

use App\Enums\MikrotikSyncStatus;
use App\Jobs\PushExpiredProfileToMikrotikJob;
use App\Jobs\RemoveExpiredProfileFromMikrotikJob;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\Tenant;
use App\Services\Network\Contracts\RouterOsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Revisi Grup Profil (Langkah 3) — RouterOS live-push for a NAS's own
 * "Profile Pelanggan Expired" fallback `/ppp profile`. Same
 * anonymous-fake-RouterOsGateway-with-a-recorder pattern as
 * NetworkProfileGroupMikrotikSyncTest — never a real raw-socket call.
 */
class ExpiredProfileMikrotikSyncTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{method: string, args: array}> */
    private array $recordedCalls = [];

    /**
     * @param  array{success: bool, message: ?string}  $result
     */
    private function bindGateway(array $result = ['success' => true, 'message' => null]): void
    {
        $recorder = &$this->recordedCalls;

        $this->app->bind(RouterOsGateway::class, function () use ($result, &$recorder) {
            return new class($result, $recorder) implements RouterOsGateway
            {
                public function __construct(private readonly array $result, private array &$recorder) {}

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

                public function syncPppProfile(Nas $nas, string $comment, string $name, ?string $remoteAddress, ?string $dnsServer, ?string $parentQueue, ?string $localAddress = null): array
                {
                    $this->recorder[] = ['method' => 'syncPppProfile', 'args' => compact('comment', 'name', 'remoteAddress', 'dnsServer', 'parentQueue', 'localAddress')];

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

    public function test_push_job_syncs_ppp_profile_with_null_remote_address_and_the_pool_as_local_address(): void
    {
        $this->bindGateway();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Expired-Pool']);
        $nas->update(['expired_ip_pool_id' => $pool->id]);

        $job = new PushExpiredProfileToMikrotikJob($nas->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $nas->refresh();
        $this->assertSame(MikrotikSyncStatus::Synced, $nas->expired_profile_mikrotik_sync_status);
        $this->assertNotNull($nas->expired_profile_mikrotik_synced_at);

        $call = $this->recordedCalls[0];
        $this->assertSame('syncPppProfile', $call['method']);
        $this->assertSame($nas->expiredProfileMikrotikComment(), $call['args']['comment']);
        $this->assertSame($nas->expiredProfileMikrotikName(), $call['args']['name']);
        $this->assertNull($call['args']['remoteAddress']);
        $this->assertNull($call['args']['dnsServer']);
        $this->assertNull($call['args']['parentQueue']);
        $this->assertSame('Expired-Pool', $call['args']['localAddress']);
    }

    public function test_push_job_skips_gracefully_when_nas_has_no_expired_pool(): void
    {
        $this->bindGateway();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $job = new PushExpiredProfileToMikrotikJob($nas->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame([], $this->recordedCalls);
    }

    public function test_push_job_releases_with_backoff_on_a_transient_failure(): void
    {
        $this->bindGateway(['success' => false, 'message' => 'connection timed out']);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $nas->update(['expired_ip_pool_id' => $pool->id]);

        $job = new PushExpiredProfileToMikrotikJob($nas->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 1;
        $job->handle(app(RouterOsGateway::class));

        $job->assertReleased(delay: 30);
    }

    public function test_push_job_marks_failed_on_the_final_attempt(): void
    {
        $this->bindGateway(['success' => false, 'message' => 'connection timed out']);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $nas->update(['expired_ip_pool_id' => $pool->id]);

        $job = new PushExpiredProfileToMikrotikJob($nas->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 3;
        $job->handle(app(RouterOsGateway::class));

        $job->assertNotReleased();
        $this->assertSame(MikrotikSyncStatus::Failed, $nas->fresh()->expired_profile_mikrotik_sync_status);
    }

    public function test_remove_job_removes_the_ppp_profile_by_comment(): void
    {
        $this->bindGateway();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $job = new RemoveExpiredProfileFromMikrotikJob($nas->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $this->assertSame('removePppProfile', $this->recordedCalls[0]['method']);
        $this->assertSame($nas->expiredProfileMikrotikComment(), $this->recordedCalls[0]['args']['comment']);
    }
}
