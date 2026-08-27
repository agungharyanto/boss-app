<?php

namespace Tests\Feature\Network;

use App\Enums\OltPonType;
use App\Livewire\Network\OltDeviceIndex;
use App\Models\Nas;
use App\Models\OltDevice;
use App\Models\OltManufacturer;
use App\Models\OltModel;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\Contracts\RouterOsGateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OltDeviceIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Same fake-binding pattern as NasServiceTest/NasIndexLivewireTest —
     * RouterOsGateway talks raw sockets, no Http::fake() equivalent.
     */
    private function bindGateway(bool $reachable): void
    {
        $this->app->bind(RouterOsGateway::class, fn () => new class($reachable) implements RouterOsGateway
        {
            public function __construct(private readonly bool $reachable) {}

            public function ping(Nas $nas): array
            {
                return ['online' => $this->reachable, 'message' => null];
            }

            public function pingHost(Nas $nas, string $targetIp, int $count = 2): bool
            {
                return $this->reachable;
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

            public function provisionApiUser(Nas $nas, string $a, string $b, string $c, string $d): array
            {
                return ['success' => true, 'message' => null];
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
            ->test(OltDeviceIndex::class)
            ->assertForbidden();
    }

    public function test_save_is_blocked_without_a_successful_test_connection(): void
    {
        $this->bindGateway(reachable: true);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $manufacturer = OltManufacturer::factory()->create();
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $manufacturer->id, 'supported_pon_type' => OltPonType::Gpon]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->set('name', 'OLT Belum Dites')
            ->set('nasId', $nas->id)
            ->set('ipAddress', '10.1.1.5')
            ->set('oltModelId', $model->id)
            ->set('accessProtocol', 'telnet')
            ->set('telnetPort', 2333)
            ->set('telnetUsername', 'admin')
            ->set('snmpVersion', 'v2c')
            ->set('snmpPort', 2161)
            ->set('snmpRoCommunity', 'public')
            // Deliberately NEVER calling testConnection() here.
            ->call('save')
            ->assertHasErrors('ipAddress');

        $this->assertDatabaseMissing('olt_devices', ['name' => 'OLT Belum Dites']);
    }

    public function test_successful_test_connection_unlocks_save(): void
    {
        $this->bindGateway(reachable: true);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $manufacturer = OltManufacturer::factory()->create();
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $manufacturer->id, 'supported_pon_type' => OltPonType::Gpon]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->set('name', 'OLT Sudah Dites')
            ->set('nasId', $nas->id)
            ->set('ipAddress', '10.1.1.5')
            ->set('oltModelId', $model->id)
            ->set('accessProtocol', 'telnet')
            ->set('telnetPort', 2333)
            ->set('telnetUsername', 'admin')
            ->set('snmpVersion', 'v2c')
            ->set('snmpPort', 2161)
            ->set('snmpRoCommunity', 'public')
            ->call('testConnection')
            ->assertSet('testConnectionResult.result', 'success')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('olt_devices', [
            'name' => 'OLT Sudah Dites',
            'nas_id' => $nas->id,
            'ip_address' => '10.1.1.5',
            'last_connection_test_result' => 'success',
        ]);
    }

    public function test_failed_test_connection_keeps_save_blocked(): void
    {
        $this->bindGateway(reachable: false);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $manufacturer = OltManufacturer::factory()->create();
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $manufacturer->id, 'supported_pon_type' => OltPonType::Gpon]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->set('name', 'OLT Gagal Dites')
            ->set('nasId', $nas->id)
            ->set('ipAddress', '10.1.1.9')
            ->set('oltModelId', $model->id)
            ->set('accessProtocol', 'telnet')
            ->set('telnetPort', 2333)
            ->set('telnetUsername', 'admin')
            ->set('snmpVersion', 'v2c')
            ->set('snmpPort', 2161)
            ->set('snmpRoCommunity', 'public')
            ->call('testConnection')
            ->assertSet('testConnectionResult.result', 'failed')
            ->call('save')
            ->assertHasErrors('ipAddress');

        $this->assertDatabaseMissing('olt_devices', ['name' => 'OLT Gagal Dites']);
    }

    /**
     * The core gating requirement: a test that passed for one IP must NOT
     * still count once the IP is changed — save() must re-block until a
     * fresh test passes for the NEW value.
     */
    public function test_changing_ip_after_a_passed_test_invalidates_it_and_re_blocks_save(): void
    {
        $this->bindGateway(reachable: true);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $manufacturer = OltManufacturer::factory()->create();
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $manufacturer->id, 'supported_pon_type' => OltPonType::Gpon]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->set('name', 'OLT Ganti IP')
            ->set('nasId', $nas->id)
            ->set('ipAddress', '10.1.1.5')
            ->set('oltModelId', $model->id)
            ->set('accessProtocol', 'telnet')
            ->set('telnetPort', 2333)
            ->set('telnetUsername', 'admin')
            ->set('snmpVersion', 'v2c')
            ->set('snmpPort', 2161)
            ->set('snmpRoCommunity', 'public')
            ->call('testConnection')
            ->assertSet('testConnectionResult.result', 'success')
            // Change IP AFTER the test passed for the old one.
            ->set('ipAddress', '10.1.1.99')
            ->assertSet('testConnectionResult', null)
            ->call('save')
            ->assertHasErrors('ipAddress');

        $this->assertDatabaseMissing('olt_devices', ['name' => 'OLT Ganti IP']);
    }

    public function test_public_ip_is_rejected_even_with_a_passed_test(): void
    {
        // Gateway reports "reachable" regardless (it's faked) — the point
        // is the private-IP guard rejects this BEFORE the save-gate check
        // even matters, so a real one never gets exercised against a
        // production router pinging a public address on our behalf.
        $this->bindGateway(reachable: true);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $manufacturer = OltManufacturer::factory()->create();
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $manufacturer->id, 'supported_pon_type' => OltPonType::Gpon]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->set('name', 'OLT IP Publik')
            ->set('nasId', $nas->id)
            ->set('ipAddress', '8.8.8.8')
            ->set('oltModelId', $model->id)
            ->set('accessProtocol', 'telnet')
            ->set('telnetPort', 2333)
            ->set('telnetUsername', 'admin')
            ->set('snmpVersion', 'v2c')
            ->set('snmpPort', 2161)
            ->set('snmpRoCommunity', 'public')
            ->call('testConnection')
            ->call('save')
            ->assertHasErrors('ipAddress');

        $this->assertDatabaseMissing('olt_devices', ['name' => 'OLT IP Publik']);
    }

    public function test_credentials_are_encrypted_and_never_plaintext_in_storage(): void
    {
        $this->bindGateway(reachable: true);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $manufacturer = OltManufacturer::factory()->create();
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $manufacturer->id, 'supported_pon_type' => OltPonType::Gpon]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->set('name', 'OLT Encrypted')
            ->set('nasId', $nas->id)
            ->set('ipAddress', '10.1.1.5')
            ->set('oltModelId', $model->id)
            ->set('accessProtocol', 'telnet')
            ->set('telnetPort', 2333)
            ->set('telnetUsername', 'admin')
            ->set('snmpVersion', 'v2c')
            ->set('snmpPort', 2161)
            ->set('snmpRoCommunity', 'super-secret-community')
            ->call('testConnection')
            ->call('save')
            ->assertHasNoErrors();

        $raw = \DB::table('olt_devices')->where('name', 'OLT Encrypted')->first();
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('super-secret-community', $raw->snmp_ro_community);

        $model = OltDevice::where('name', 'OLT Encrypted')->first();
        $this->assertSame('super-secret-community', $model->snmp_ro_community);
    }

    public function test_manufacturer_and_model_can_be_added_inline_from_the_form(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->set('newManufacturerName', 'ZTE')
            ->call('addManufacturer')
            ->assertHasNoErrors();

        $manufacturer = OltManufacturer::where('name', 'ZTE')->firstOrFail();

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->set('oltManufacturerId', $manufacturer->id)
            ->set('newModelName', 'C300')
            ->set('newModelPonType', 'gpon')
            ->call('addModel')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('olt_models', [
            'olt_manufacturer_id' => $manufacturer->id,
            'name' => 'C300',
            'supported_pon_type' => 'gpon',
        ]);
    }

    public function test_admin_can_edit_and_delete_an_existing_olt_device(): void
    {
        $this->bindGateway(reachable: true);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $manufacturer = OltManufacturer::factory()->create();
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $manufacturer->id, 'supported_pon_type' => OltPonType::Gpon]);
        $olt = OltDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'nas_id' => $nas->id,
            'olt_model_id' => $model->id,
            'ip_address' => '10.1.1.5',
            // Mirrors what a row created via the real save() flow always
            // has — a recorded SUCCESSFUL test for its own current combo
            // (save() enforces this at creation time).
            'last_connection_test_at' => now(),
            'last_connection_test_result' => 'success',
            'last_connection_test_message' => 'Berhasil (test setup).',
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('edit', $olt->id)
            ->assertSet('name', $olt->name)
            ->set('name', 'OLT Sudah Diedit')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('olt_devices', ['id' => $olt->id, 'name' => 'OLT Sudah Diedit']);

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('delete', $olt->id);

        $this->assertDatabaseMissing('olt_devices', ['id' => $olt->id]);
    }

    /**
     * Addendum #2 — create() must pre-fill both SNMP community fields with
     * a real, non-blank random value the moment the form opens, before
     * the user has touched anything.
     */
    public function test_create_auto_generates_both_snmp_community_fields(): void
    {
        $tenant = Tenant::factory()->create();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create');

        $ro = $component->get('snmpRoCommunity');
        $rw = $component->get('snmpRwCommunity');

        $this->assertNotSame('', $ro);
        $this->assertNotSame('', $rw);
        $this->assertNotSame($ro, $rw);
        $this->assertGreaterThanOrEqual(12, strlen($ro));
    }

    /**
     * The "Regenerate" buttons must produce a genuinely different value,
     * not just re-render the same one.
     */
    public function test_regenerate_snmp_community_produces_a_different_value(): void
    {
        $tenant = Tenant::factory()->create();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create');

        $originalRo = $component->get('snmpRoCommunity');
        $originalRw = $component->get('snmpRwCommunity');

        $component->call('regenerateSnmpRoCommunity');
        $this->assertNotSame($originalRo, $component->get('snmpRoCommunity'));

        $component->call('regenerateSnmpRwCommunity');
        $this->assertNotSame($originalRw, $component->get('snmpRwCommunity'));
    }

    /**
     * The actual bug this addendum fixes: SNMP used to live inside the
     * per-protocol conditional block, so switching Access Protocol wiped
     * whatever SNMP values were typed. SNMP must now survive untouched.
     */
    public function test_snmp_fields_survive_switching_access_protocol(): void
    {
        $this->bindGateway(reachable: true);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $manufacturer = OltManufacturer::factory()->create();
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $manufacturer->id, 'supported_pon_type' => OltPonType::Gpon]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->set('name', 'OLT Ganti Protokol')
            ->set('nasId', $nas->id)
            ->set('ipAddress', '10.1.1.5')
            ->set('oltModelId', $model->id)
            ->set('snmpRoCommunity', 'community-ro-tetap')
            ->set('snmpRwCommunity', 'community-rw-tetap')
            ->set('accessProtocol', 'telnet')
            ->set('telnetPort', 2333)
            ->set('telnetUsername', 'admin')
            ->assertSet('snmpRoCommunity', 'community-ro-tetap')
            ->assertSet('snmpRwCommunity', 'community-rw-tetap')
            // Switch to SSH — SNMP values must still survive.
            ->set('accessProtocol', 'ssh')
            ->set('sshPort', 22)
            ->set('sshUsername', 'admin')
            ->assertSet('snmpRoCommunity', 'community-ro-tetap')
            ->assertSet('snmpRwCommunity', 'community-rw-tetap')
            ->call('testConnection')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('olt_devices', ['name' => 'OLT Ganti Protokol', 'access_protocol' => 'ssh']);

        $saved = OltDevice::where('name', 'OLT Ganti Protokol')->firstOrFail();
        $this->assertSame('community-ro-tetap', $saved->snmp_ro_community);
        $this->assertSame('community-rw-tetap', $saved->snmp_rw_community);
    }

    /**
     * SNMP RO community is required on CREATE (it's auto-generated, so a
     * blank value only happens if the user deliberately clears it).
     */
    public function test_snmp_ro_community_is_required_on_create(): void
    {
        $this->bindGateway(reachable: true);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $manufacturer = OltManufacturer::factory()->create();
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $manufacturer->id, 'supported_pon_type' => OltPonType::Gpon]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->set('name', 'OLT Tanpa Community')
            ->set('nasId', $nas->id)
            ->set('ipAddress', '10.1.1.5')
            ->set('oltModelId', $model->id)
            ->set('accessProtocol', 'telnet')
            ->set('telnetPort', 2333)
            ->set('telnetUsername', 'admin')
            ->set('snmpRoCommunity', '')
            ->call('testConnection')
            ->call('save')
            ->assertHasErrors('snmpRoCommunity');

        $this->assertDatabaseMissing('olt_devices', ['name' => 'OLT Tanpa Community']);
    }

    /**
     * Editing an existing row must NOT force re-entering the SNMP RO
     * community — blank means "keep the already-saved value", same
     * masked-secret convention as telnet/ssh passwords.
     */
    public function test_editing_without_touching_snmp_ro_community_keeps_the_existing_value(): void
    {
        $this->bindGateway(reachable: true);
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $manufacturer = OltManufacturer::factory()->create();
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $manufacturer->id, 'supported_pon_type' => OltPonType::Gpon]);
        $olt = OltDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'nas_id' => $nas->id,
            'olt_model_id' => $model->id,
            'ip_address' => '10.1.1.5',
            'snmp_ro_community' => 'community-lama',
            'last_connection_test_at' => now(),
            'last_connection_test_result' => 'success',
            'last_connection_test_message' => 'Berhasil (test setup).',
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('edit', $olt->id)
            ->assertSet('snmpRoCommunity', '')
            ->set('name', 'OLT Edit Tanpa Ganti Community')
            ->call('save')
            ->assertHasNoErrors();

        $olt->refresh();
        $this->assertSame('community-lama', $olt->snmp_ro_community);
    }

    /**
     * Addendum #3, Bug 1 — regression guard. The real bug (table stays
     * empty after a successful save, no manual browser refresh helps) was
     * only observable via a real DOM morph, confirmed with a live headless
     * Playwright run against this server, not via PHPUnit (Livewire's
     * testing engine only renders server HTML, it never executes Alpine/
     * DataTables JS or DOM morphing). This assertion is a cheap guard
     * against someone removing wire:ignore later without realizing why
     * it's there — it does NOT re-prove the original bug by itself.
     */
    public function test_the_datatables_list_container_is_wire_ignored(): void
    {
        $tenant = Tenant::factory()->create();

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->html();

        $this->assertMatchesRegularExpression(
            '/wire:ignore[^>]*>\s*<table id="olt-devices-table"/s',
            $html
        );
    }

    /**
     * Addendum #3, Bug 3 — regression guard for the show/hide toggle on
     * telnet_password/ssh_password. Also DOM/Alpine behavior, not
     * something Livewire's testing engine executes — this only asserts
     * the toggle markup is actually rendered for both fields.
     */
    public function test_telnet_and_ssh_password_fields_have_a_show_hide_toggle(): void
    {
        $tenant = Tenant::factory()->create();

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->set('accessProtocol', 'telnet')
            ->html();

        $this->assertStringContainsString('wire:model="telnetPassword"', $html);
        $this->assertStringContainsString('showPw = !showPw', $html);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->set('accessProtocol', 'ssh')
            ->html();

        $this->assertStringContainsString('wire:model="sshPassword"', $html);
        $this->assertStringContainsString('showPw = !showPw', $html);
    }

    /**
     * Addendum #3, Bug 2 — a model still referenced by an olt_devices row
     * must be blocked from deletion, with a friendly error naming the
     * count. Deliberately checked with withoutGlobalScopes() in the
     * component (see deleteModel()'s own docblock) — this test creates
     * the referencing device under the SAME tenant for simplicity, the
     * cross-tenant case is implicitly covered by that withoutGlobalScopes()
     * call being unconditional.
     */
    public function test_deleting_a_model_still_used_by_an_olt_device_is_blocked(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $manufacturer = OltManufacturer::factory()->create();
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $manufacturer->id, 'supported_pon_type' => OltPonType::Gpon]);
        OltDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'nas_id' => $nas->id,
            'olt_model_id' => $model->id,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->set('oltManufacturerId', $manufacturer->id)
            ->call('deleteModel', $model->id)
            ->assertSet('modelDeleteError', 'Tidak bisa dihapus — masih dipakai oleh 1 perangkat OLT.');

        $this->assertDatabaseHas('olt_models', ['id' => $model->id]);
    }

    public function test_deleting_an_unused_model_succeeds(): void
    {
        $tenant = Tenant::factory()->create();
        $manufacturer = OltManufacturer::factory()->create();
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $manufacturer->id, 'supported_pon_type' => OltPonType::Gpon]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->set('oltManufacturerId', $manufacturer->id)
            ->call('deleteModel', $model->id)
            ->assertSet('modelDeleteError', null);

        $this->assertDatabaseMissing('olt_models', ['id' => $model->id]);
    }

    /**
     * A manufacturer with any model under it (used or not) is blocked —
     * deleteManufacturer() deliberately requires an empty manufacturer
     * rather than cascading, see its own docblock for why.
     */
    public function test_deleting_a_manufacturer_that_still_has_models_is_blocked(): void
    {
        $tenant = Tenant::factory()->create();
        $manufacturer = OltManufacturer::factory()->create();
        OltModel::factory()->create(['olt_manufacturer_id' => $manufacturer->id, 'supported_pon_type' => OltPonType::Gpon]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->call('deleteManufacturer', $manufacturer->id)
            ->assertSet('manufacturerDeleteError', 'Tidak bisa dihapus — manufacturer ini masih punya 1 model. Hapus model-modelnya dulu.');

        $this->assertDatabaseHas('olt_manufacturers', ['id' => $manufacturer->id]);
    }

    public function test_deleting_an_empty_manufacturer_succeeds(): void
    {
        $tenant = Tenant::factory()->create();
        $manufacturer = OltManufacturer::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->call('deleteManufacturer', $manufacturer->id)
            ->assertSet('manufacturerDeleteError', null);

        $this->assertDatabaseMissing('olt_manufacturers', ['id' => $manufacturer->id]);
    }

    /**
     * Deleting the manufacturer/model currently selected in the OPEN form
     * must clear the now-dangling selection, not leave a form pointing at
     * an id that no longer exists.
     */
    public function test_deleting_the_currently_selected_manufacturer_clears_the_form_selection(): void
    {
        $tenant = Tenant::factory()->create();
        $manufacturer = OltManufacturer::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(OltDeviceIndex::class)
            ->call('create')
            ->set('oltManufacturerId', $manufacturer->id)
            ->call('deleteManufacturer', $manufacturer->id)
            ->assertSet('oltManufacturerId', null)
            ->assertSet('oltModelId', null);
    }
}
