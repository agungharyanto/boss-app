<?php

namespace Tests\Feature\Network;

use App\Models\Nas;
use App\Models\Tenant;
use App\Services\Network\FreeradiusVirtualServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Covers file-writing only — the actual radiusd restart/port-bind is
 * entrypoint.sh's job (a shell supervisor loop inside the freeradius
 * container, watching this same directory), which isn't something a
 * sqlite-backed feature test can exercise. That mechanism (including the
 * real "SIGHUP alone does NOT open new listen sockets" + "FreeRADIUS
 * refuses a world-writable $INCLUDE'd directory" + "port 18120 collides
 * with FreeRADIUS's own stock inner-tunnel listener" findings) was
 * verified against the real running container — see CLAUDE.md v0.6.5.
 */
class FreeradiusVirtualServerServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $configDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configDir = sys_get_temp_dir().'/freeradius-nas-config-'.uniqid();
        config(['services.freeradius.nas_config_dir' => $this->configDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->configDir);

        parent::tearDown();
    }

    public function test_sync_writes_a_listen_block_for_auth_and_acct_ports(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create([
            'tenant_id' => $tenant->id,
            'auth_port' => 20000,
            'acct_port' => 20001,
            'radius_secret' => 'super-secret',
        ]);

        app(FreeradiusVirtualServerService::class)->sync($nas);

        $listen = File::get("{$this->configDir}/listen/nas-{$nas->id}.conf");
        $this->assertStringContainsString('port = 20000', $listen);
        $this->assertStringContainsString('port = 20001', $listen);
        $this->assertStringContainsString("clients = nas_{$nas->id}_clients", $listen);

        $clients = File::get("{$this->configDir}/clients/nas-{$nas->id}.conf");
        $this->assertStringContainsString('secret = "super-secret"', $clients);
        $this->assertStringContainsString('172.28.0.0/24', $clients);

        // Regression test: real MikroTik NAS traffic was being silently
        // discarded by radiusd.conf's global require_message_authenticator
        // = yes (RouterOS doesn't send Message-Authenticator on PPP
        // CHAP/MSCHAP Access-Requests) — must be scoped off per-client here,
        // not left to the insecure global default.
        $this->assertStringContainsString('require_message_authenticator = no', $clients);
    }

    public function test_sync_skips_a_nas_with_no_allocated_ports_yet(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->unprovisionedPorts()->create(['tenant_id' => $tenant->id]);

        app(FreeradiusVirtualServerService::class)->sync($nas);

        $this->assertFileDoesNotExist("{$this->configDir}/listen/nas-{$nas->id}.conf");
    }

    public function test_remove_deletes_both_generated_files(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id, 'auth_port' => 20010, 'acct_port' => 20011]);

        $service = app(FreeradiusVirtualServerService::class);
        $service->sync($nas);
        $this->assertFileExists("{$this->configDir}/listen/nas-{$nas->id}.conf");

        $service->remove($nas);

        $this->assertFileDoesNotExist("{$this->configDir}/listen/nas-{$nas->id}.conf");
        $this->assertFileDoesNotExist("{$this->configDir}/clients/nas-{$nas->id}.conf");
    }
}
