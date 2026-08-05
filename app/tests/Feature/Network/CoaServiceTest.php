<?php

namespace Tests\Feature\Network;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnProtocol;
use App\Exceptions\CoaTimeoutException;
use App\Exceptions\CoaUnavailableException;
use App\Models\Nas;
use App\Models\Tenant;
use App\Models\VpnAccount;
use App\Services\Network\CoaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The real radclient dispatch happens inside the freeradius container
 * (docker/freeradius/coa-worker.sh, watched by entrypoint.sh's poll loop)
 * — not something a sqlite-backed feature test can exercise directly (same
 * reasoning as VpnCheckNodeHealthTest / FreeradiusVirtualServerServiceTest).
 * These tests cover CoaService's own queueing/polling/timeout logic, using
 * a lightweight background shell watcher (in place of the real container)
 * to simulate coa-worker.sh picking up the request and writing a result —
 * this still exercises the REAL file-based protocol end to end, just with
 * a stand-in worker. The real mechanism (radclient inside freeradius,
 * narrow reverse iptables exception, self-healing routes) was verified
 * separately against test-x86-bajastu — see CLAUDE.md v0.6.5.
 */
class CoaServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $configDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configDir = sys_get_temp_dir().'/coa-service-test-'.uniqid();
        config(['services.freeradius.nas_config_dir' => $this->configDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->configDir);

        parent::tearDown();
    }

    private function nasWithActiveAccount(VpnProtocol $protocol = VpnProtocol::OpenVpn): Nas
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create([
            'tenant_id' => $tenant->id,
            'coa_port' => 3799,
            'radius_secret' => 'the-real-secret',
        ]);

        VpnAccount::factory()->create([
            'nas_id' => $nas->id,
            'protocol' => $protocol,
            'status' => VpnAccountStatus::Active,
            'internal_ip' => '172.23.194.50',
        ]);

        return $nas;
    }

    /**
     * Backgrounds a tiny shell watcher that stands in for coa-worker.sh:
     * waits for the first non-result *.json file to appear in the queue
     * dir, verifies its content matches what CoaService is documented to
     * send, and writes back a canned result.
     */
    private function fakeCoaWorker(string $expectedTargetIp, int $expectedPort, string $expectedSecret): void
    {
        $queueDir = "{$this->configDir}/coa-queue";
        File::ensureDirectoryExists($queueDir);

        $script = <<<SH
        for i in \$(seq 1 50); do
            f=\$(ls {$queueDir}/*.json 2>/dev/null | grep -v '\\.result\\.json' | head -1)
            if [ -n "\$f" ]; then
                if grep -q '"target_ip":"{$expectedTargetIp}"' "\$f" \
                    && grep -q '"port":{$expectedPort}' "\$f" \
                    && grep -q '"secret":"{$expectedSecret}"' "\$f"; then
                    echo '{"ok":true,"raw":"Received Disconnect-ACK"}' > "\${f%.json}.result.json"
                else
                    echo '{"ok":false,"raw":"content mismatch"}' > "\${f%.json}.result.json"
                fi
                rm -f "\$f"
                break
            fi
            sleep 0.1
        done
        SH;

        shell_exec('('.$script.') > /dev/null 2>&1 &');
    }

    public function test_disconnect_sends_the_correct_target_and_returns_the_worker_result(): void
    {
        $nas = $this->nasWithActiveAccount();
        $this->fakeCoaWorker('172.23.194.50', 3799, 'the-real-secret');

        $result = app(CoaService::class)->disconnect($nas, 'delinquent-customer');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Disconnect-ACK', $result['raw']);
    }

    public function test_disconnect_throws_when_no_active_openvpn_or_wireguard_account_exists(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(CoaUnavailableException::class);

        app(CoaService::class)->disconnect($nas, 'someuser');
    }

    public function test_disconnect_ignores_a_revoked_account_and_throws(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        VpnAccount::factory()->revoked()->create(['nas_id' => $nas->id, 'protocol' => VpnProtocol::OpenVpn]);

        $this->expectException(CoaUnavailableException::class);

        app(CoaService::class)->disconnect($nas, 'someuser');
    }

    public function test_disconnect_ignores_an_active_l2tp_only_account_and_throws(): void
    {
        // L2TP/IPsec known limitation — CoA deliberately unsupported for it.
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        VpnAccount::factory()->create([
            'nas_id' => $nas->id, 'protocol' => VpnProtocol::L2tpIpsec, 'status' => VpnAccountStatus::Active,
        ]);

        $this->expectException(CoaUnavailableException::class);

        app(CoaService::class)->disconnect($nas, 'someuser');
    }

    public function test_disconnect_times_out_when_no_worker_ever_responds(): void
    {
        $nas = $this->nasWithActiveAccount();
        // Deliberately no fakeCoaWorker() call — nothing will ever consume
        // the request file.

        $this->expectException(CoaTimeoutException::class);

        app(CoaService::class)->disconnect($nas, 'someuser');
    }

    public function test_coa_request_uses_the_coa_type_not_disconnect(): void
    {
        $nas = $this->nasWithActiveAccount(VpnProtocol::WireGuard);
        $queueDir = "{$this->configDir}/coa-queue";
        File::ensureDirectoryExists($queueDir);

        shell_exec('(for i in $(seq 1 50); do f=$(ls '.$queueDir.'/*.json 2>/dev/null | grep -v ".result.json" | head -1); '
            .'if [ -n "$f" ]; then if grep -q \'"type":"coa"\' "$f"; then echo \'{"ok":true,"raw":"Received CoA-ACK"}\' > "${f%.json}.result.json"; '
            .'else echo \'{"ok":false,"raw":"wrong type"}\' > "${f%.json}.result.json"; fi; rm -f "$f"; break; fi; sleep 0.1; done) > /dev/null 2>&1 &');

        $result = app(CoaService::class)->coaRequest($nas, 'someuser');

        $this->assertTrue($result['ok']);
    }
}
