<?php

namespace Tests\Feature\Network;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnIpPoolStatus;
use App\Exceptions\VpnIpPoolExhaustedException;
use App\Exceptions\VpnProvisioningException;
use App\Models\Nas;
use App\Models\Tenant;
use App\Models\VpnIpPool;
use App\Models\VpnServer;
use App\Services\Network\VpnProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class VpnProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $pkiDir;

    private string $ccdDir;

    protected function setUp(): void
    {
        parent::setUp();

        // easyrsa/openssl themselves are never actually invoked in tests
        // (Process::fake() below) — but VpnProvisioningService checks for a
        // real ca.crt file to distinguish "PKI not bootstrapped yet" from a
        // genuine provisioning attempt, so a throwaway directory with a
        // dummy file stands in for the shared vpn-pki volume.
        $this->pkiDir = sys_get_temp_dir().'/vpn-pki-test-'.uniqid();
        $this->ccdDir = sys_get_temp_dir().'/vpn-ccd-test-'.uniqid();
        File::makeDirectory($this->pkiDir.'/issued', 0777, true);
        File::makeDirectory($this->ccdDir, 0777, true);
        File::put($this->pkiDir.'/ca.crt', 'dummy-ca');

        config(['services.vpn.pki_dir' => $this->pkiDir, 'services.vpn.ccd_dir' => $this->ccdDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->pkiDir);
        File::deleteDirectory($this->ccdDir);

        parent::tearDown();
    }

    private function fakeSuccessfulEasyRsa(): void
    {
        Process::fake([
            '*easyrsa*build-client-full*' => Process::result(output: ''),
            '*openssl*' => Process::result(output: "serial=A1B2C3D4E5F60708\n"),
            '*easyrsa*revoke*' => Process::result(output: ''),
            '*easyrsa*gen-crl*' => Process::result(output: ''),
        ]);
    }

    private function serverWithPool(int $poolSize = 3): VpnServer
    {
        $server = VpnServer::factory()->create(['subnet_cidr' => '172.23.200.0/29']);
        $server->provisionIpPool();

        return $server;
    }

    public function test_provision_allocates_ip_issues_cert_and_writes_ccd_file(): void
    {
        $this->fakeSuccessfulEasyRsa();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $this->serverWithPool();

        $account = app(VpnProvisioningService::class)->provision($nas);

        $this->assertSame('nas-'.$nas->id, $account->username);
        $this->assertSame(VpnAccountStatus::Active, $account->status);
        $this->assertSame('A1B2C3D4E5F60708', $account->cert_serial);
        $this->assertNotNull($account->internal_ip);
        $this->assertFileExists($this->ccdDir.'/'.$account->username);
        $this->assertStringContainsString('ifconfig-push '.$account->internal_ip, File::get($this->ccdDir.'/'.$account->username));

        $this->assertDatabaseHas('vpn_ip_pool', [
            'ip_address' => $account->internal_ip,
            'status' => VpnIpPoolStatus::Assigned->value,
            'vpn_account_id' => $account->id,
        ]);
    }

    public function test_provision_refuses_a_second_active_account_for_the_same_nas(): void
    {
        $this->fakeSuccessfulEasyRsa();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $this->serverWithPool();

        app(VpnProvisioningService::class)->provision($nas);

        $this->expectException(VpnProvisioningException::class);

        app(VpnProvisioningService::class)->provision($nas);
    }

    public function test_provision_throws_when_ip_pool_is_exhausted(): void
    {
        $this->fakeSuccessfulEasyRsa();
        $tenant = Tenant::factory()->create();
        $server = $this->serverWithPool(); // 172.23.200.0/29 -> 8 total minus network/broadcast/gateway = 5 addresses

        foreach (range(1, 5) as $i) {
            $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
            app(VpnProvisioningService::class)->provision($nas);
        }

        $lastNas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(VpnIpPoolExhaustedException::class);

        app(VpnProvisioningService::class)->provision($lastNas);
    }

    public function test_provision_rolls_back_ip_allocation_when_easyrsa_fails(): void
    {
        Process::fake([
            '*easyrsa*build-client-full*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
        ]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $server = $this->serverWithPool();

        try {
            app(VpnProvisioningService::class)->provision($nas);
            $this->fail('Expected VpnProvisioningException was not thrown.');
        } catch (VpnProvisioningException) {
            // expected
        }

        $this->assertDatabaseMissing('vpn_accounts', ['nas_id' => $nas->id]);
        $this->assertDatabaseHas('vpn_servers', ['id' => $server->id, 'current_clients' => 0]);
        $this->assertDatabaseCount('vpn_ip_pool', 5); // all still available (default /29 pool size)
        $this->assertSame(
            5,
            VpnIpPool::where('vpn_server_id', $server->id)
                ->where('status', VpnIpPoolStatus::Available->value)
                ->count()
        );
    }

    public function test_revoke_frees_the_ip_and_deletes_the_ccd_file(): void
    {
        $this->fakeSuccessfulEasyRsa();
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $this->serverWithPool();

        $account = app(VpnProvisioningService::class)->provision($nas);
        $ccdFile = $this->ccdDir.'/'.$account->username;
        $this->assertFileExists($ccdFile);

        $revoked = app(VpnProvisioningService::class)->revoke($account);

        $this->assertSame(VpnAccountStatus::Revoked, $revoked->status);
        $this->assertNotNull($revoked->revoked_at);
        $this->assertFileDoesNotExist($ccdFile);
        $this->assertDatabaseHas('vpn_ip_pool', [
            'ip_address' => $account->internal_ip,
            'status' => VpnIpPoolStatus::Available->value,
            'vpn_account_id' => null,
        ]);
        $this->assertDatabaseHas('vpn_servers', ['id' => $account->vpn_server_id, 'current_clients' => 0]);
    }

    public function test_provision_refuses_when_pki_not_yet_bootstrapped(): void
    {
        File::delete($this->pkiDir.'/ca.crt');
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $this->serverWithPool();

        $this->expectException(VpnProvisioningException::class);

        app(VpnProvisioningService::class)->provision($nas);
    }
}
