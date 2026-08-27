<?php

namespace Tests\Feature\Network;

use App\Enums\MikrotikSyncStatus;
use App\Jobs\PushCustomerIpPoolToMikrotikJob;
use App\Jobs\RemoveCustomerIpPoolFromMikrotikJob;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\Contracts\RouterOsGateway;
use App\Services\Network\CustomerIpPoolService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * v0.14.2.1 — RouterOS live-push for CustomerIpPool. Never calls a real
 * router — RouterOsGateway is bound to an anonymous fake implementation
 * (same pattern already established by NasServiceTest/
 * NasApiUserProvisioningServiceTest/OltDeviceServiceTest for this exact
 * socket-based, non-HTTP transport), and Job retry/release logic is
 * exercised via Laravel's own withFakeQueueInteractions() rather than a
 * real queue connection.
 */
class CustomerIpPoolMikrotikSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    private function bindGateway(bool $success, ?string $message = null): void
    {
        $this->app->bind(RouterOsGateway::class, fn () => new class($success, $message) implements RouterOsGateway
        {
            public function __construct(private readonly bool $success, private readonly ?string $message) {}

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
                return ['success' => $this->success, 'message' => $this->success ? null : $this->message];
            }

            public function removeIpPool(Nas $nas, string $comment): array
            {
                return ['success' => $this->success, 'message' => $this->success ? null : $this->message];
            }

            public function syncPppProfile(Nas $nas, string $comment, string $name, string $remoteAddress, ?string $dnsServer, ?string $parentQueue): array
            {
                return ['success' => $this->success, 'message' => $this->success ? null : $this->message];
            }

            public function removePppProfile(Nas $nas, string $comment): array
            {
                return ['success' => $this->success, 'message' => $this->success ? null : $this->message];
            }

            public function syncHotspotServerPool(Nas $nas, string $poolName): array
            {
                return ['success' => $this->success, 'message' => $this->success ? null : $this->message];
            }

            public function syncHotspotUserProfile(Nas $nas, string $lookupName, string $targetName, ?string $rateLimit, int $sharedUsers, ?string $sessionTimeout, ?string $addressPool = null): array
            {
                return ['success' => $this->success, 'message' => $this->success ? null : $this->message];
            }

            public function removeHotspotUserProfile(Nas $nas, string $lookupName): array
            {
                return ['success' => $this->success, 'message' => $this->success ? null : $this->message];
            }
        });
    }

    // --- Service dispatch wiring ---------------------------------------

    public function test_create_dispatches_the_push_job(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $this->actingAs($this->admin($tenant));
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $pool = app(CustomerIpPoolService::class)->create([
            'nas_id' => $nas->id,
            'name' => 'Pool Push',
            'network_address' => '192.168.10.0/24',
            'gateway_ip' => '192.168.10.1',
            'range_start' => '192.168.10.10',
            'range_end' => '192.168.10.200',
        ]);

        Bus::assertDispatched(PushCustomerIpPoolToMikrotikJob::class, fn ($job) => $job->customerIpPoolId === $pool->id);
        $this->assertSame(MikrotikSyncStatus::Pending, $pool->mikrotik_sync_status);
    }

    public function test_update_dispatches_the_push_job_and_resets_status_to_pending(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs($this->admin($tenant));
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $pool->markSynced();

        Bus::fake();

        app(CustomerIpPoolService::class)->update($pool, ['name' => 'Nama Baru']);

        Bus::assertDispatched(PushCustomerIpPoolToMikrotikJob::class, fn ($job) => $job->customerIpPoolId === $pool->id);
        $this->assertSame(MikrotikSyncStatus::Pending, $pool->fresh()->mikrotik_sync_status);
    }

    public function test_delete_dispatches_the_remove_job(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs($this->admin($tenant));
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        Bus::fake();

        app(CustomerIpPoolService::class)->delete($pool);

        Bus::assertDispatched(RemoveCustomerIpPoolFromMikrotikJob::class, fn ($job) => $job->customerIpPoolId === $pool->id);
    }

    public function test_resync_dispatches_the_push_job_and_resets_status_to_pending(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs($this->admin($tenant));
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $pool->markSyncFailed('timeout sebelumnya');

        Bus::fake();

        app(CustomerIpPoolService::class)->resync($pool);

        Bus::assertDispatched(PushCustomerIpPoolToMikrotikJob::class, fn ($job) => $job->customerIpPoolId === $pool->id);
        $this->assertSame(MikrotikSyncStatus::Pending, $pool->fresh()->mikrotik_sync_status);
    }

    // --- PushCustomerIpPoolToMikrotikJob::handle() ----------------------

    public function test_push_job_marks_the_pool_synced_on_gateway_success(): void
    {
        $this->bindGateway(success: true);

        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        $job = new PushCustomerIpPoolToMikrotikJob($pool->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $pool->refresh();
        $this->assertSame(MikrotikSyncStatus::Synced, $pool->mikrotik_sync_status);
        $this->assertNotNull($pool->mikrotik_synced_at);
        $this->assertNull($pool->mikrotik_sync_error);
        $job->assertNotReleased();
    }

    public function test_push_job_releases_with_backoff_on_a_non_final_failed_attempt(): void
    {
        $this->bindGateway(success: false, message: 'connection timed out');

        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        $job = new PushCustomerIpPoolToMikrotikJob($pool->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 1;
        $job->handle(app(RouterOsGateway::class));

        $job->assertReleased(delay: 30);
        $pool->refresh();
        // Not yet marked Failed — still mid-retry, error message recorded
        // for visibility but the status stays whatever it already was
        // (Pending, from create()/update()/resync()).
        $this->assertSame(MikrotikSyncStatus::Pending, $pool->mikrotik_sync_status);
        $this->assertSame('connection timed out', $pool->mikrotik_sync_error);
    }

    public function test_push_job_marks_failed_with_error_message_on_the_final_attempt(): void
    {
        $this->bindGateway(success: false, message: 'connection timed out');

        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        $job = new PushCustomerIpPoolToMikrotikJob($pool->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 3; // === $job->tries, the final attempt.
        $job->handle(app(RouterOsGateway::class));

        $job->assertNotReleased();
        $pool->refresh();
        $this->assertSame(MikrotikSyncStatus::Failed, $pool->mikrotik_sync_status);
        $this->assertSame('connection timed out', $pool->mikrotik_sync_error);
    }

    public function test_push_job_failed_callback_marks_the_pool_failed(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        $job = new PushCustomerIpPoolToMikrotikJob($pool->id);
        $job->failed(new \RuntimeException('serialization exploded'));

        $pool->refresh();
        $this->assertSame(MikrotikSyncStatus::Failed, $pool->mikrotik_sync_status);
        $this->assertSame('serialization exploded', $pool->mikrotik_sync_error);
    }

    public function test_push_job_skips_gracefully_when_the_pool_no_longer_exists(): void
    {
        $this->bindGateway(success: true);

        $job = new PushCustomerIpPoolToMikrotikJob(999999);
        $job->withFakeQueueInteractions();

        // Must not throw.
        $job->handle(app(RouterOsGateway::class));
        $job->assertNotReleased();
    }

    // --- RemoveCustomerIpPoolFromMikrotikJob::handle() ------------------

    public function test_remove_job_succeeds_silently_when_the_gateway_reports_success(): void
    {
        $this->bindGateway(success: true);

        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $pool->delete();

        $job = new RemoveCustomerIpPoolFromMikrotikJob($pool->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(RouterOsGateway::class));

        $job->assertNotReleased();
        $this->assertNull($pool->fresh()->mikrotik_sync_error);
    }

    public function test_remove_job_still_finds_a_soft_deleted_pool_and_records_a_final_failure(): void
    {
        $this->bindGateway(success: false, message: 'pool still in use');

        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $pool->delete();

        $job = new RemoveCustomerIpPoolFromMikrotikJob($pool->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = 3;
        $job->handle(app(RouterOsGateway::class));

        $job->assertNotReleased();
        $this->assertStringContainsString('pool still in use', $pool->fresh()->mikrotik_sync_error);
    }
}
