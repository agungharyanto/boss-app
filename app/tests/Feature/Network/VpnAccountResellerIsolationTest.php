<?php

namespace Tests\Feature\Network;

use App\Models\Nas;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VpnAccount;
use App\Models\VpnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class VpnAccountResellerIsolationTest extends TestCase
{
    use RefreshDatabase;

    private string $pkiDir;

    private string $ccdDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pkiDir = sys_get_temp_dir().'/vpn-pki-test-'.uniqid();
        $this->ccdDir = sys_get_temp_dir().'/vpn-ccd-test-'.uniqid();
        File::makeDirectory($this->pkiDir.'/issued', 0777, true);
        File::makeDirectory($this->ccdDir, 0777, true);
        File::put($this->pkiDir.'/ca.crt', 'dummy-ca');
        config(['services.vpn.pki_dir' => $this->pkiDir, 'services.vpn.ccd_dir' => $this->ccdDir]);

        Process::fake([
            '*easyrsa*build-client-full*' => Process::result(output: ''),
            '*openssl*' => Process::result(output: "serial=A1B2C3D4E5F60708\n"),
            '*easyrsa*revoke*' => Process::result(output: ''),
            '*easyrsa*gen-crl*' => Process::result(output: ''),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->pkiDir);
        File::deleteDirectory($this->ccdDir);

        parent::tearDown();
    }

    private function resellerOwner(Tenant $tenant, Reseller $reseller): User
    {
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        return $owner;
    }

    public function test_reseller_a_cannot_provision_a_vpn_account_for_reseller_bs_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $nasB = Nas::factory()->forReseller($resellerB)->create();
        $server = VpnServer::factory()->create(['subnet_cidr' => '172.23.200.0/29']);
        $server->provisionIpPool();

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $this->actingAs($ownerA)
            ->postJson("/api/v1/nas/{$nasB->id}/vpn-account")
            ->assertNotFound();

        $this->assertDatabaseMissing('vpn_accounts', ['nas_id' => $nasB->id]);
    }

    public function test_reseller_a_cannot_revoke_a_vpn_account_belonging_to_reseller_bs_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $nasB = Nas::factory()->forReseller($resellerB)->create();
        $server = VpnServer::factory()->create(['subnet_cidr' => '172.23.200.0/29']);
        $server->provisionIpPool();

        $ownerB = $this->resellerOwner($tenant, $resellerB);
        $this->actingAs($ownerB)->postJson("/api/v1/nas/{$nasB->id}/vpn-account")->assertCreated();
        $accountB = VpnAccount::where('nas_id', $nasB->id)->firstOrFail();

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $this->actingAs($ownerA)
            ->postJson("/api/v1/vpn-accounts/{$accountB->id}/revoke")
            ->assertForbidden();

        $this->assertDatabaseHas('vpn_accounts', ['id' => $accountB->id, 'status' => 'active']);
    }

    public function test_reseller_owner_can_provision_and_revoke_their_own_nas_vpn_account(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $nas = Nas::factory()->forReseller($reseller)->create();
        $server = VpnServer::factory()->create(['subnet_cidr' => '172.23.200.0/29']);
        $server->provisionIpPool();

        $owner = $this->resellerOwner($tenant, $reseller);

        $response = $this->actingAs($owner)->postJson("/api/v1/nas/{$nas->id}/vpn-account");
        $response->assertCreated();
        $accountId = $response->json('data.id');

        $this->actingAs($owner)
            ->postJson("/api/v1/vpn-accounts/{$accountId}/revoke")
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');
    }
}
