<?php

namespace Tests\Feature\Network;

use App\Enums\MikrotikSyncStatus;
use App\Jobs\PushNetworkProfileGroupToMikrotikJob;
use App\Livewire\Network\NetworkProfileGroupIndex;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\Contracts\RouterOsGateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class NetworkProfileGroupIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config(['database.connections.radius' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('radius');

        DB::connection('radius')->statement('
            CREATE TABLE radgroupreply (
                id INTEGER PRIMARY KEY,
                groupname TEXT,
                attribute TEXT,
                op TEXT,
                value TEXT
            )
        ');
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    /**
     * Revisi Grup Profil — same anonymous-fake-RouterOsGateway pattern as
     * NetworkProfileGroupMikrotikSyncTest (never a real raw-socket call in
     * the automated suite). `$counter` is an object (not a primitive) so
     * its mutation inside the anonymous class is visible to the test
     * without needing a by-reference constructor param (PHP disallows
     * combining constructor property promotion with by-reference
     * parameters) — used to prove listInterfaces() is actually CACHED
     * (called once, not once per render).
     */
    private function bindListInterfaces(array $interfaces, ?object $counter = null): void
    {
        $counter ??= new \stdClass;
        $counter->calls = 0;

        $this->app->bind(RouterOsGateway::class, function () use ($interfaces, $counter) {
            return new class($interfaces, $counter) implements RouterOsGateway
            {
                public function __construct(private readonly array $interfaces, private readonly object $counter) {}

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
                    $this->counter->calls++;

                    return $this->interfaces;
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

    public function test_creating_a_group_via_the_form(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('nasId', (string) $nas->id)
            ->set('name', 'Grup Utama')
            ->set('type', 'ppp')
            ->set('customerIpPoolId', (string) $pool->id)
            ->call('createGroup')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('network_profile_groups', ['nas_id' => $nas->id, 'name' => 'Grup Utama']);
    }

    /**
     * v0.14.4 amendment — see CustomerIpPoolIndexLivewireTest's own
     * docblock for the full investigation (Agung's "NAS harus di atas
     * Simpan" report) — no data race found, backend 'required' already
     * existed but was never explicitly tested.
     */
    public function test_submitting_without_selecting_a_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('name', 'Grup Tanpa NAS')
            ->set('type', 'ppp')
            ->call('createGroup');

        $component->assertHasErrors('nasId');
        $this->assertDatabaseMissing('network_profile_groups', ['name' => 'Grup Tanpa NAS']);
    }

    public function test_simpan_button_is_disabled_until_a_nas_is_selected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $hasDisabledAttribute = fn (string $buttonHtml): bool => (bool) preg_match('/\bdisabled\b(?!:)/', $buttonHtml);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('showCreateForm', true);

        preg_match('/<button type="submit"[^>]*>/', $component->html(), $before);
        $this->assertNotEmpty($before, 'Simpan button not found in rendered HTML');
        $this->assertTrue($hasDisabledAttribute($before[0]));

        $htmlAfterSelectingNas = $component->set('nasId', (string) $nas->id)->html();
        preg_match('/<button type="submit"[^>]*>/', $htmlAfterSelectingNas, $after);
        $this->assertNotEmpty($after, 'Simpan button not found in rendered HTML');
        $this->assertFalse($hasDisabledAttribute($after[0]));
    }

    public function test_changing_nas_in_the_create_form_resets_the_selected_pool(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $poolA = CustomerIpPool::factory()->create(['nas_id' => $nasA->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('nasId', (string) $nasA->id)
            ->set('customerIpPoolId', (string) $poolA->id)
            ->set('nasId', (string) $nasB->id)
            ->assertSet('customerIpPoolId', '');
    }

    // --- Revisi Grup Profil: interface/VLAN + PPPoE Server ---------------

    public function test_changing_nas_in_the_create_form_resets_the_selected_interface(): void
    {
        $this->bindListInterfaces([['name' => 'vlan110-PPPoE-10Mbps', 'type' => 'vlan']]);
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('nasId', (string) $nasA->id)
            ->set('interfaceName', 'vlan110-PPPoE-10Mbps')
            ->set('nasId', (string) $nasB->id)
            ->assertSet('interfaceName', '');
    }

    public function test_interface_dropdown_is_populated_from_the_selected_nas_and_cached(): void
    {
        $counter = new \stdClass;
        $this->bindListInterfaces([
            ['name' => 'vlan110-PPPoE-10Mbps', 'type' => 'vlan'],
            ['name' => 'ether1', 'type' => 'ether'],
        ], $counter);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('showCreateForm', true)
            ->set('nasId', (string) $nas->id)
            // re-render twice more (search is unrelated, just forces a
            // fresh render() call) — proves the 30s Cache::remember TTL
            // actually prevents re-querying RouterOS on every render, not
            // just on the first one.
            ->set('search', 'a')
            ->set('search', '')
            ->html();

        $this->assertStringContainsString('vlan110-PPPoE-10Mbps', $html);
        $this->assertStringContainsString('ether1', $html);
        $this->assertSame(1, $counter->calls);
    }

    public function test_creating_a_ppp_group_with_interface_and_service_name(): void
    {
        $this->bindListInterfaces([['name' => 'vlan110-PPPoE-10Mbps', 'type' => 'vlan']]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('nasId', (string) $nas->id)
            ->set('name', 'Grup PPPoE')
            ->set('type', 'ppp')
            ->set('customerIpPoolId', (string) $pool->id)
            ->set('interfaceName', 'vlan110-PPPoE-10Mbps')
            ->set('serviceName', 'PPPoE-Vlan110-10Mbps')
            ->call('createGroup')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('network_profile_groups', [
            'name' => 'Grup PPPoE',
            'interface_name' => 'vlan110-PPPoE-10Mbps',
            'service_name' => 'PPPoE-Vlan110-10Mbps',
        ]);
    }

    /**
     * interfaceName/serviceName are only meaningful for type=ppp (see
     * NetworkProfileGroup's own docblock) — even if an admin typed
     * something in while Tipe happened to be PPP, submitting as Hotspot
     * must never persist it.
     */
    public function test_creating_a_hotspot_group_never_persists_interface_or_service_name(): void
    {
        $this->bindListInterfaces([]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'usage_type' => 'hotspot']);

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('nasId', (string) $nas->id)
            ->set('name', 'Grup Hotspot')
            ->set('interfaceName', 'vlan110-PPPoE-10Mbps')
            ->set('serviceName', 'some-service')
            ->set('type', 'hotspot')
            ->set('customerIpPoolId', (string) $pool->id)
            ->call('createGroup')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('network_profile_groups', [
            'name' => 'Grup Hotspot',
            'interface_name' => null,
            'service_name' => null,
        ]);
    }

    public function test_editing_a_group_loads_its_stored_interface_and_service_name(): void
    {
        $this->bindListInterfaces([['name' => 'vlan110-PPPoE-10Mbps', 'type' => 'vlan']]);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id,
            'customer_ip_pool_id' => $pool->id,
            'type' => 'ppp',
            'interface_name' => 'vlan110-PPPoE-10Mbps',
            'service_name' => 'PPPoE-Vlan110-10Mbps',
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->call('edit', $group->id)
            ->assertSet('editInterfaceName', 'vlan110-PPPoE-10Mbps')
            ->assertSet('editServiceName', 'PPPoE-Vlan110-10Mbps');
    }

    /**
     * Verifikasi UI (2026-08-28) — Agung tidak menemukan field Interface/VLAN
     * saat mengedit sebuah Grup Profil; investigasi ulang membuktikan field-nya
     * memang benar SENGAJA disembunyikan untuk Tipe=Hotspot (bukan bug), yang
     * paling mungkin adalah root cause laporan itu (2 dari 3 Grup Profil
     * existing di NAS produksi bertipe Hotspot). Hint text ini menutup celah
     * UX-nya — absennya field sekarang menjelaskan dirinya sendiri.
     */
    public function test_create_form_shows_a_hint_instead_of_the_interface_fields_when_type_is_hotspot(): void
    {
        $tenant = Tenant::factory()->create();

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('showCreateForm', true)
            ->set('type', 'hotspot')
            ->html();

        $this->assertStringNotContainsString('wire:model="interfaceName"', $html);
        $this->assertStringContainsString('hanya tersedia untuk Tipe = PPP', $html);
    }

    public function test_edit_form_shows_a_hint_instead_of_the_interface_fields_when_type_is_hotspot(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'usage_type' => 'hotspot']);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'type' => 'hotspot']);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->call('edit', $group->id)
            ->html();

        $this->assertStringNotContainsString('wire:model="editInterfaceName"', $html);
        $this->assertStringContainsString('hanya tersedia untuk Tipe = PPP', $html);
    }

    public function test_pool_dropdown_only_lists_pools_belonging_to_the_selected_nas(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create(['nas_id' => $nasA->id, 'name' => 'Pool Milik A']);
        CustomerIpPool::factory()->create(['nas_id' => $nasB->id, 'name' => 'Pool Milik B']);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('showCreateForm', true)
            ->set('nasId', (string) $nasA->id)
            ->html();

        $this->assertStringContainsString('Pool Milik A', $html);
        $this->assertStringNotContainsString('Pool Milik B', $html);
    }

    /**
     * v0.14.3.1 — same "invalidate the dependent field on parent change"
     * discipline as changing NAS already has, applied to Tipe.
     */
    public function test_changing_type_in_the_create_form_resets_the_selected_pool(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'usage_type' => 'ppp']);

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('nasId', (string) $nas->id)
            ->set('type', 'ppp')
            ->set('customerIpPoolId', (string) $pool->id)
            ->set('type', 'hotspot')
            ->assertSet('customerIpPoolId', '');
    }

    public function test_pool_dropdown_only_lists_pools_compatible_with_the_selected_type(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Pool PPP', 'usage_type' => 'ppp']);
        CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Pool Hotspot', 'usage_type' => 'hotspot']);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('showCreateForm', true)
            ->set('nasId', (string) $nas->id)
            ->set('type', 'ppp')
            ->html();

        $this->assertStringContainsString('Pool PPP', $html);
        $this->assertStringNotContainsString('Pool Hotspot', $html);
    }

    public function test_general_pool_appears_in_the_dropdown_for_both_types(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'name' => 'Pool Umum', 'usage_type' => 'general']);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('showCreateForm', true)
            ->set('nasId', (string) $nas->id);

        $this->assertStringContainsString('Pool Umum', $component->set('type', 'ppp')->html());
        $this->assertStringContainsString('Pool Umum', $component->set('type', 'hotspot')->html());
    }

    /**
     * Backend enforcement, not just the dropdown filter — forcing an
     * incompatible pool id directly (bypassing the updatedType() reset a
     * real user interaction would trigger) proves the server-side check
     * itself works.
     */
    public function test_a_hotspot_only_pool_is_rejected_for_a_ppp_group_via_the_form(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'usage_type' => 'hotspot']);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('nasId', (string) $nas->id)
            ->set('name', 'Grup Utama')
            ->set('type', 'ppp')
            ->set('customerIpPoolId', (string) $pool->id)
            ->call('createGroup');

        $component->assertHasErrors('customerIpPoolId');
        $this->assertDatabaseMissing('network_profile_groups', ['name' => 'Grup Utama']);
    }

    public function test_customer_ip_pool_from_a_different_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $poolFromNasB = CustomerIpPool::factory()->create(['nas_id' => $nasB->id]);

        // Directly forcing customerIpPoolId to a mismatched value —
        // bypassing the updatedNasId() reset a real user interaction would
        // trigger — to prove the SERVER-SIDE cross-check itself works,
        // not just the client-side reset convenience.
        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('nasId', (string) $nasA->id)
            ->set('name', 'Grup Utama')
            ->set('customerIpPoolId', (string) $poolFromNasB->id)
            ->call('createGroup');

        $component->assertHasErrors('customerIpPoolId');
        $this->assertDatabaseMissing('network_profile_groups', ['name' => 'Grup Utama']);
    }

    /** Same real bug as NetworkProfileGroupApiTest — see that file's own docblock. */
    public function test_a_soft_deleted_customer_ip_pool_is_rejected_on_create(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $pool->delete();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('nasId', (string) $nas->id)
            ->set('name', 'Grup Utama')
            ->set('customerIpPoolId', (string) $pool->id)
            ->call('createGroup');

        $component->assertHasErrors('customerIpPoolId');
        $this->assertDatabaseMissing('network_profile_groups', ['name' => 'Grup Utama']);
    }

    public function test_editing_after_the_linked_pool_was_soft_deleted_fails_cleanly(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id]);
        $pool->delete();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->call('edit', $group->id)
            ->set('editName', 'Nama Baru Saja')
            ->call('updateGroup');

        $component->assertHasErrors('editCustomerIpPoolId');
        $this->assertDatabaseHas('network_profile_groups', ['id' => $group->id, 'name' => $group->name]);
    }

    public function test_same_group_name_on_the_same_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'name' => 'Grup Utama']);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('nasId', (string) $nas->id)
            ->set('name', 'Grup Utama')
            ->set('customerIpPoolId', (string) $pool->id)
            ->call('createGroup');

        $component->assertHasErrors('name');
    }

    public function test_editing_a_group(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'name' => 'Lama']);

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->call('edit', $group->id)
            ->set('editName', 'Baru')
            ->call('updateGroup')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('network_profile_groups', ['id' => $group->id, 'name' => 'Baru']);
    }

    public function test_editing_to_a_pool_from_a_different_nas_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nasA = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $nasB = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $poolA = CustomerIpPool::factory()->create(['nas_id' => $nasA->id]);
        $poolB = CustomerIpPool::factory()->create(['nas_id' => $nasB->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nasA->id, 'customer_ip_pool_id' => $poolA->id]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->call('edit', $group->id)
            ->set('editCustomerIpPoolId', (string) $poolB->id)
            ->call('updateGroup');

        $component->assertHasErrors('editCustomerIpPoolId');
    }

    public function test_changing_edit_type_resets_the_selected_pool(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pppPool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'usage_type' => 'ppp']);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pppPool->id, 'type' => 'ppp']);

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->call('edit', $group->id)
            ->assertSet('editCustomerIpPoolId', (string) $pppPool->id)
            ->set('editType', 'hotspot')
            ->assertSet('editCustomerIpPoolId', '');
    }

    public function test_editing_to_an_incompatible_usage_type_pool_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pppPool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'usage_type' => 'ppp']);
        $hotspotOnlyPool = CustomerIpPool::factory()->create(['nas_id' => $nas->id, 'usage_type' => 'hotspot']);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pppPool->id, 'type' => 'ppp']);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->call('edit', $group->id)
            ->set('editCustomerIpPoolId', (string) $hotspotOnlyPool->id)
            ->call('updateGroup');

        $component->assertHasErrors('editCustomerIpPoolId');
    }

    public function test_deleting_a_group_soft_deletes_it(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->call('deleteGroup', $group->id);

        $this->assertSoftDeleted('network_profile_groups', ['id' => $group->id]);
    }

    public function test_non_admin_tier_user_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        Livewire::actingAs($user)->test(NetworkProfileGroupIndex::class)->assertForbidden();
    }

    // --- Auto-refresh (reused from v0.14.2.2) ---------------------------

    public function test_wire_poll_is_present_when_a_visible_row_is_pending(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id]); // defaults Pending

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->html();

        $this->assertStringContainsString('wire:poll.5s="$refresh"', $html);
    }

    public function test_wire_poll_is_absent_when_no_visible_row_is_pending(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id]);
        $group->markSynced();

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->html();

        $this->assertStringNotContainsString('wire:poll', $html);
    }

    public function test_muat_ulang_button_is_wired_to_refresh(): void
    {
        $tenant = Tenant::factory()->create();

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->html();

        $this->assertStringContainsString('wire:click="$refresh"', $html);
        $this->assertStringContainsString('Muat Ulang', $html);
    }

    public function test_sync_ulang_button_only_shows_for_a_failed_group(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $pending = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'name' => 'Grup Pending']);
        $failed = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'name' => 'Grup Gagal']);
        $failed->markSyncFailed('router unreachable');

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->html();

        $this->assertStringContainsString('resyncGroup('.$failed->id.')', $html);
        $this->assertStringNotContainsString('resyncGroup('.$pending->id.')', $html);
    }

    public function test_resync_group_re_dispatches_the_push_job(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id]);
        $group->markSyncFailed('router unreachable');

        Bus::fake();

        Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->call('resyncGroup', $group->id);

        Bus::assertDispatched(PushNetworkProfileGroupToMikrotikJob::class, fn ($job) => $job->networkProfileGroupId === $group->id);
        $this->assertSame(MikrotikSyncStatus::Pending, $group->fresh()->mikrotik_sync_status);
    }
}
