<?php

namespace Tests\Feature\Network;

use App\Enums\NasStatus;
use App\Exceptions\NasNotProvisionedException;
use App\Models\Nas;
use App\Models\Tenant;
use App\Services\Network\Contracts\RouterOsGateway;
use App\Services\Network\NasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class NasServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $freeradiusConfigDir;

    protected function setUp(): void
    {
        parent::setUp();

        // v0.6.5 — NasService::create()/update()/delete() now write real
        // files via FreeradiusVirtualServerService; redirect to a temp dir
        // so these pre-existing tests don't need to know about it, same
        // isolation approach as FreeradiusVirtualServerServiceTest.
        $this->freeradiusConfigDir = sys_get_temp_dir().'/freeradius-nas-config-'.uniqid();
        config(['services.freeradius.nas_config_dir' => $this->freeradiusConfigDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->freeradiusConfigDir);

        parent::tearDown();
    }

    /**
     * RouterOsGateway talks raw sockets (evilfreelancer/routeros-api-php),
     * not HTTP — there's no Http::fake() equivalent, so we bind a fake
     * implementation of the interface instead (see
     * App\Providers\AppServiceProvider's default binding).
     */
    private function bindGateway(bool $online, ?string $message = null): void
    {
        $this->app->bind(RouterOsGateway::class, fn () => new class($online, $message) implements RouterOsGateway
        {
            public function __construct(private readonly bool $online, private readonly ?string $message) {}

            public function ping(Nas $nas): array
            {
                return ['online' => $this->online, 'message' => $this->message];
            }

            public function pingHost(Nas $nas, string $targetIp, int $count = 2): bool
            {
                return true;
            }

            public function provisionApiUser(Nas $nas, string $connectAsUsername, string $connectAsPassword, string $newApiUsername, string $newApiPassword): array
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
                return ['success' => true, 'message' => null];
            }

            public function removeHotspotUserProfile(Nas $nas, string $lookupName): array
            {
                return ['success' => true, 'message' => null];
            }
        });
    }

    public function test_test_connection_marks_nas_online_on_success(): void
    {
        $this->bindGateway(online: true);

        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->provisioned()->create(['tenant_id' => $tenant->id, 'status' => NasStatus::Unknown]);

        $result = app(NasService::class)->testConnection($nas);

        $this->assertSame(NasStatus::Online, $result->status);
        $this->assertNotNull($result->last_ping_at);
    }

    public function test_test_connection_marks_nas_offline_on_failure(): void
    {
        $this->bindGateway(online: false, message: 'connection refused');

        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->provisioned()->create(['tenant_id' => $tenant->id, 'status' => NasStatus::Online]);

        $result = app(NasService::class)->testConnection($nas);

        $this->assertSame(NasStatus::Offline, $result->status);
        $this->assertNotNull($result->last_ping_at);
    }

    public function test_test_connection_refuses_when_mikrotik_ip_is_not_yet_provisioned(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id, 'mikrotik_ip' => null]);

        $this->expectException(NasNotProvisionedException::class);

        app(NasService::class)->testConnection($nas);
    }

    public function test_encrypted_columns_round_trip_and_never_appear_raw_in_the_database(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create([
            'tenant_id' => $tenant->id,
            'api_password' => 'plaintext-api-password',
            'radius_secret' => 'plaintext-radius-secret',
        ]);

        $this->assertSame('plaintext-api-password', $nas->fresh()->api_password);
        $this->assertSame('plaintext-radius-secret', $nas->fresh()->radius_secret);

        $raw = DB::table('nas')->where('id', $nas->id)->first();
        $this->assertStringNotContainsString('plaintext-api-password', $raw->api_password);
        $this->assertStringNotContainsString('plaintext-radius-secret', $raw->radius_secret);
    }

    public function test_create_allocates_ports_automatically_and_writes_virtual_server_config(): void
    {
        $tenant = Tenant::factory()->create();

        $nas = app(NasService::class)->create([
            'name' => 'nas-baru',
            'radius_secret' => 'a-secret',
        ], $tenant->id, null);

        $this->assertNotNull($nas->auth_port);
        $this->assertSame($nas->auth_port + 1, $nas->acct_port);
        $this->assertFileExists("{$this->freeradiusConfigDir}/listen/nas-{$nas->id}.conf");
        $this->assertFileExists("{$this->freeradiusConfigDir}/clients/nas-{$nas->id}.conf");
    }

    public function test_create_never_allocates_the_same_ports_for_two_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $service = app(NasService::class);

        $first = $service->create(['name' => 'nas-a', 'radius_secret' => 'secret-a'], $tenant->id, null);
        $second = $service->create(['name' => 'nas-b', 'radius_secret' => 'secret-b'], $tenant->id, null);

        $this->assertNotSame($first->auth_port, $second->auth_port);
    }

    public function test_delete_removes_the_generated_virtual_server_config(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = app(NasService::class)->create(['name' => 'nas-hapus', 'radius_secret' => 'secret'], $tenant->id, null);
        $configPath = "{$this->freeradiusConfigDir}/listen/nas-{$nas->id}.conf";
        $this->assertFileExists($configPath);

        app(NasService::class)->delete($nas);

        $this->assertFileDoesNotExist($configPath);
    }
}
