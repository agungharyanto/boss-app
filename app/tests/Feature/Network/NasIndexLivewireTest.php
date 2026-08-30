<?php

namespace Tests\Feature\Network;

use App\Enums\MikrotikSyncStatus;
use App\Enums\NasStatus;
use App\Enums\VpnAccountStatus;
use App\Jobs\PushExpiredProfileToMikrotikJob;
use App\Jobs\RemoveExpiredProfileFromMikrotikJob;
use App\Livewire\Network\NasIndex;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VpnAccount;
use App\Services\Network\Contracts\RouterOsGateway;
use App\Support\ResellerContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class NasIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * RouterOsGateway talks raw sockets, not HTTP — same fake-binding
     * pattern as NasServiceTest, no Http::fake() equivalent exists.
     */
    private function bindGateway(bool $online, ?string $message = null, bool $provisionSuccess = true): void
    {
        $this->app->bind(RouterOsGateway::class, fn () => new class($online, $message, $provisionSuccess) implements RouterOsGateway
        {
            public function __construct(private readonly bool $online, private readonly ?string $message, private readonly bool $provisionSuccess) {}

            public function ping(Nas $nas): array
            {
                return ['online' => $this->online, 'message' => $this->message];
            }

            public function pingHost(Nas $nas, string $targetIp, int $count = 2): bool
            {
                return true;
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

            public function provisionApiUser(Nas $nas, string $connectAsUsername, string $connectAsPassword, string $newApiUsername, string $newApiPassword): array
            {
                return ['success' => $this->provisionSuccess, 'message' => $this->provisionSuccess ? null : 'invalid admin credential'];
            }
        });
    }

    private function admin(Tenant $tenant): User
    {
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');

        return $admin;
    }

    public function test_non_admin_non_reseller_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(NasIndex::class)
            ->assertForbidden();
    }

    public function test_admin_can_render_and_list_existing_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id, 'name' => 'NAS Gambir']);

        Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->assertOk()
            ->assertSee('NAS Gambir');
    }

    public function test_admin_can_create_a_direct_nas(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->call('create')
            ->set('name', 'NAS Baru')
            ->set('timezone', 'Asia/Jakarta')
            ->set('mikrotikIp', '192.168.1.1')
            ->set('apiPort', 8728)
            ->set('apiUsername', 'admin')
            ->set('apiPassword', 'rahasia123')
            ->set('radiusSecret', 'radiussecret123')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('nas', [
            'name' => 'NAS Baru',
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'mikrotik_ip' => '192.168.1.1',
        ]);
    }

    public function test_create_requires_radius_secret(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->call('create')
            ->set('name', 'NAS Tanpa Secret')
            ->set('radiusSecret', '')
            ->call('save')
            ->assertHasErrors(['radiusSecret']);

        $this->assertDatabaseMissing('nas', ['name' => 'NAS Tanpa Secret']);
    }

    public function test_editing_does_not_erase_secrets_left_blank(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create([
            'tenant_id' => $tenant->id,
            'api_password' => 'original-api-password',
            'radius_secret' => 'original-radius-secret',
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->call('edit', $nas->id)
            ->set('name', 'NAS Updated Name')
            ->set('apiPassword', '')
            ->set('radiusSecret', '')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $nas->fresh();
        $this->assertSame('NAS Updated Name', $fresh->name);
        $this->assertSame('original-api-password', $fresh->api_password);
        $this->assertSame('original-radius-secret', $fresh->radius_secret);
    }

    public function test_mikrotik_ip_is_locked_once_an_active_vpn_account_exists(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        VpnAccount::factory()->create([
            'nas_id' => $nas->id,
            'status' => VpnAccountStatus::Active,
        ]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->call('edit', $nas->id);

        $this->assertTrue($component->instance()->isMikrotikIpLocked());

        $component->set('mikrotikIp', '10.10.10.10')->call('save');

        // The submitted mikrotik_ip must be ignored while locked.
        $this->assertNotSame('10.10.10.10', $nas->fresh()->mikrotik_ip);
    }

    public function test_reseller_owner_creating_nas_is_automatically_attributed_to_their_own_reseller(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        // The Livewire component itself doesn't run HTTP middleware
        // (ResolveResellerContext), so bind the context directly — same
        // technique as ResellerTaxPolicyIndexLivewireTest.
        app(ResellerContext::class)->set($reseller);

        Livewire::actingAs($owner)
            ->test(NasIndex::class)
            ->call('create')
            ->set('name', 'NAS Reseller A')
            ->set('radiusSecret', 'secretvalue')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('nas', ['name' => 'NAS Reseller A', 'reseller_id' => $reseller->id]);
    }

    public function test_reseller_a_cannot_see_reseller_bs_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        Nas::factory()->forReseller($resellerB)->create(['name' => 'NAS Milik B']);

        $ownerA = User::factory()->create(['tenant_id' => $tenant->id]);
        $resellerA->users()->attach($ownerA->id, ['role' => 'owner', 'status' => 'active']);

        app(ResellerContext::class)->set($resellerA);

        Livewire::actingAs($ownerA)
            ->test(NasIndex::class)
            ->assertDontSee('NAS Milik B');
    }

    public function test_reseller_a_cannot_edit_reseller_bs_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->forReseller($resellerB)->create();

        $ownerA = User::factory()->create(['tenant_id' => $tenant->id]);
        $resellerA->users()->attach($ownerA->id, ['role' => 'owner', 'status' => 'active']);

        Livewire::actingAs($ownerA)
            ->test(NasIndex::class)
            ->call('edit', $nasB->id)
            ->assertForbidden();
    }

    public function test_test_connection_succeeds_and_persists_status_for_an_existing_nas(): void
    {
        $this->bindGateway(online: true);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id, 'status' => NasStatus::Unknown]);

        Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->call('edit', $nas->id)
            ->set('mikrotikIp', '192.168.88.1')
            ->call('testConnection')
            ->assertSet('testConnectionResult.status', 'success');

        $fresh = $nas->fresh();
        $this->assertSame(NasStatus::Online, $fresh->status);
        $this->assertNotNull($fresh->last_ping_at);
    }

    public function test_test_connection_shows_raw_error_message_on_failure(): void
    {
        $this->bindGateway(online: false, message: 'RouterOS: connection timed out');
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->call('edit', $nas->id)
            ->set('mikrotikIp', '192.168.88.1')
            ->call('testConnection');

        $component->assertSet('testConnectionResult.status', 'failed');
        $this->assertSame('RouterOS: connection timed out', $component->get('testConnectionResult')['message']);
        $this->assertSame(NasStatus::Offline, $nas->fresh()->status);
    }

    public function test_test_connection_with_a_freshly_typed_password_that_works_persists_it(): void
    {
        // Regression test for a real bug found 2026-08-04: testing with a
        // password DIFFERENT from what's stored (e.g. re-entering the
        // NAS's actual current password after it drifted out of sync) used
        // to show "success"/"online" without ever correcting the stored
        // nas.api_password — the very next real connection attempt using
        // the stale stored value would then fail again, looking like the
        // NAS randomly went offline with nothing changed.
        $this->bindGateway(online: true);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create([
            'tenant_id' => $tenant->id,
            'api_username' => 'stale-user',
            'api_password' => 'stale-password',
            'status' => NasStatus::Offline,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->call('edit', $nas->id)
            ->set('mikrotikIp', '192.168.88.1')
            ->set('apiUsername', 'the-real-user')
            ->set('apiPassword', 'the-real-password')
            ->call('testConnection')
            ->assertSet('testConnectionResult.status', 'success');

        $fresh = $nas->fresh();
        $this->assertSame(NasStatus::Online, $fresh->status);
        $this->assertSame('the-real-user', $fresh->api_username);
        $this->assertSame('the-real-password', $fresh->api_password);
    }

    public function test_test_connection_with_a_blank_password_field_never_touches_the_stored_credential(): void
    {
        $this->bindGateway(online: true);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create([
            'tenant_id' => $tenant->id,
            'api_username' => 'existing-user',
            'api_password' => 'existing-password',
            'status' => NasStatus::Unknown,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->call('edit', $nas->id)
            ->set('mikrotikIp', '192.168.88.1')
            ->set('apiPassword', '')
            ->call('testConnection')
            ->assertSet('testConnectionResult.status', 'success');

        $fresh = $nas->fresh();
        $this->assertSame(NasStatus::Online, $fresh->status);
        $this->assertSame('existing-password', $fresh->api_password);
    }

    public function test_test_connection_with_a_freshly_typed_wrong_password_does_not_persist_it(): void
    {
        $this->bindGateway(online: false, message: 'RouterOS: bad credentials');
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create([
            'tenant_id' => $tenant->id,
            'api_username' => 'existing-user',
            'api_password' => 'existing-password',
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->call('edit', $nas->id)
            ->set('mikrotikIp', '192.168.88.1')
            ->set('apiPassword', 'a-wrong-guess')
            ->call('testConnection')
            ->assertSet('testConnectionResult.status', 'failed');

        $fresh = $nas->fresh();
        $this->assertSame(NasStatus::Offline, $fresh->status);
        // A FAILED test never overwrites the stored credential — only a
        // proven-working one does.
        $this->assertSame('existing-password', $fresh->api_password);
    }

    public function test_test_connection_on_a_new_unsaved_nas_does_not_persist_anything(): void
    {
        $this->bindGateway(online: true);
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->call('create')
            ->set('mikrotikIp', '192.168.88.1')
            ->set('apiUsername', 'admin')
            ->set('apiPassword', 'pw')
            ->call('testConnection')
            ->assertSet('testConnectionResult.status', 'success');

        $this->assertDatabaseCount('nas', 0);
    }

    public function test_provision_api_user_modal_creates_dedicated_credential_and_clears_admin_fields(): void
    {
        $this->bindGateway(online: true, provisionSuccess: true);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id, 'api_username' => null, 'api_password' => null]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->call('openProvisionApiModal', $nas->id)
            ->set('provisionAdminUsername', 'router-admin')
            ->set('provisionAdminPassword', 'router-admin-password')
            ->call('provisionApiUser');

        $component->assertSet('provisionApiResult.status', 'success');
        // The admin credential is never left sitting in component state.
        $component->assertSet('provisionAdminUsername', '');
        $component->assertSet('provisionAdminPassword', '');

        $this->assertSame("boss-app-api-{$nas->id}", $nas->fresh()->api_username);
    }

    public function test_provision_api_user_modal_shows_failure_and_does_not_persist_anything(): void
    {
        $this->bindGateway(online: true, provisionSuccess: false);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id, 'api_username' => 'old-user', 'api_password' => 'old-pass']);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->call('openProvisionApiModal', $nas->id)
            ->set('provisionAdminUsername', 'router-admin')
            ->set('provisionAdminPassword', 'wrong-password')
            ->call('provisionApiUser');

        $component->assertSet('provisionApiResult.status', 'failed');
        $this->assertSame('old-user', $nas->fresh()->api_username);
        $this->assertSame('old-pass', $nas->fresh()->api_password);
    }

    public function test_reseller_a_cannot_open_provision_api_modal_for_reseller_bs_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->forReseller($resellerB)->create();

        $ownerA = User::factory()->create(['tenant_id' => $tenant->id]);
        $resellerA->users()->attach($ownerA->id, ['role' => 'owner', 'status' => 'active']);

        Livewire::actingAs($ownerA)
            ->test(NasIndex::class)
            ->call('openProvisionApiModal', $nasB->id)
            ->assertForbidden();
    }

    // --- Revisi Grup Profil (Langkah 3): Profil Pelanggan Expired --------

    public function test_setting_expired_ip_pool_marks_pending_and_dispatches_push_job(): void
    {
        Bus::fake();
        $this->bindGateway(online: true);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Expired-Pool']);

        Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->call('openExpiredProfileModal', $nas->id)
            ->set('expiredProfileIpPoolId', (string) $pool->id)
            ->call('saveExpiredProfile')
            ->assertHasNoErrors();

        $nas->refresh();
        $this->assertSame($pool->id, $nas->expired_ip_pool_id);
        $this->assertSame(MikrotikSyncStatus::Pending, $nas->expired_profile_mikrotik_sync_status);

        Bus::assertDispatched(PushExpiredProfileToMikrotikJob::class, fn ($job) => $job->nasId === $nas->id);
    }

    public function test_clearing_expired_ip_pool_dispatches_remove_job_and_resets_sync_status(): void
    {
        Bus::fake();
        $this->bindGateway(online: true);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $nas->update(['expired_ip_pool_id' => $pool->id]);
        $nas->markExpiredProfileSynced();

        Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->call('openExpiredProfileModal', $nas->id)
            ->assertSet('expiredProfileIpPoolId', (string) $pool->id)
            ->set('expiredProfileIpPoolId', '')
            ->call('saveExpiredProfile')
            ->assertHasNoErrors();

        $nas->refresh();
        $this->assertNull($nas->expired_ip_pool_id);
        $this->assertNull($nas->expired_profile_mikrotik_sync_status);

        Bus::assertDispatched(RemoveExpiredProfileFromMikrotikJob::class, fn ($job) => $job->nasId === $nas->id);
    }

    public function test_expired_ip_pool_from_a_different_nas_is_rejected(): void
    {
        $this->bindGateway(online: true);
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $poolB = CustomerIpPool::factory()->create(['nas_id' => $nasB->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(NasIndex::class)
            ->call('openExpiredProfileModal', $nasA->id)
            ->set('expiredProfileIpPoolId', (string) $poolB->id)
            ->call('saveExpiredProfile')
            ->assertHasErrors('expiredProfileIpPoolId');

        $this->assertNull($nasA->fresh()->expired_ip_pool_id);
    }

    public function test_reseller_a_cannot_open_expired_profile_modal_for_reseller_bs_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->forReseller($resellerB)->create();

        $ownerA = User::factory()->create(['tenant_id' => $tenant->id]);
        $resellerA->users()->attach($ownerA->id, ['role' => 'owner', 'status' => 'active']);

        Livewire::actingAs($ownerA)
            ->test(NasIndex::class)
            ->call('openExpiredProfileModal', $nasB->id)
            ->assertForbidden();
    }
}
