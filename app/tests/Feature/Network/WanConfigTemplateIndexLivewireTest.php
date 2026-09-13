<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\WanConfigTemplateIndex;
use App\Models\ModemType;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WanConfigTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.12.5 (revisi arsitektur) — "Template Konfig CPE" TIDAK terikat Paket
 * sama sekali, dibedakan HANYA oleh Tipe Modem. TIDAK ADA lagi validasi
 * collision (Paket, Modem) — beberapa Template boleh punya Tipe Modem yang
 * sama, dibedakan lewat `name` bebas. Satu-satunya validasi yang tersisa:
 * referential integrity Tipe Modem (assertReferencesAlive), sama pola
 * dengan sebelumnya.
 */
class WanConfigTemplateIndexLivewireTest extends TestCase
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

    // ================= viewAny / manage gate =================

    public function test_non_admin_tier_user_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user);

        Livewire::test(WanConfigTemplateIndex::class)->assertForbidden();
    }

    // ================= Create =================

    public function test_creating_a_generic_template(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('openCreateForModemType')
            ->set('name', 'Template Generic Default')
            ->set('modemTypeSelection', 'generic')
            ->set('wan1Vlan', '10')
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('wan_config_templates', [
            'tenant_id' => $tenant->id,
            'name' => 'Template Generic Default',
            'modem_type_id' => null,
            'wan1_vlan' => 10,
        ]);
    }

    public function test_creating_a_modem_specific_template(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('openCreateForModemType', $modemType->id)
            ->set('name', 'Template ZTE Standar')
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('wan_config_templates', [
            'name' => 'Template ZTE Standar',
            'modem_type_id' => $modemType->id,
        ]);
    }

    /**
     * TIDAK LAGI ditolak (kebalikan dari desain v0.12.4 lama) — beberapa
     * Template boleh punya Tipe Modem yang SAMA, dibedakan lewat nama.
     */
    public function test_two_templates_for_the_same_modem_type_are_both_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'modem_type_id' => $modemType->id, 'name' => 'Template A']);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('openCreateForModemType', $modemType->id)
            ->set('name', 'Template B')
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertSame(2, WanConfigTemplate::where('modem_type_id', $modemType->id)->count());
    }

    /**
     * Sama halnya untuk beberapa Template generic sekaligus — tidak ada
     * lagi batasan "1 default per paket" (paket sudah tidak ada).
     */
    public function test_two_generic_templates_are_both_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'modem_type_id' => null, 'name' => 'Generic A']);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('openCreateForModemType')
            ->set('name', 'Generic B')
            ->set('modemTypeSelection', 'generic')
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertSame(2, WanConfigTemplate::whereNull('modem_type_id')->count());
    }

    public function test_editing_a_template(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $template = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Nama Lama']);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('editTemplate', $template->id)
            ->set('name', 'Nama Baru')
            ->set('wan1Vlan', '999')
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertSame('Nama Baru', $template->fresh()->name);
        $this->assertSame(999, $template->fresh()->wan1_vlan);
    }

    // ================= Validasi soft-delete Tipe Modem (assertReferencesAlive) =================

    /**
     * ModemType yang soft-deleted TIDAK boleh bisa dipilih untuk membuat
     * template baru — sama pola dengan sebelumnya, masih relevan (satu-
     * satunya validasi referential integrity yang tersisa di arsitektur
     * baru).
     */
    public function test_a_soft_deleted_modem_type_is_rejected_when_creating_a_template(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        $modemType->delete();

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('openCreateForModemType', $modemType->id)
            ->set('name', 'Template Ilegal')
            ->call('saveTemplate')
            ->assertHasErrors('modemTypeSelection');

        $this->assertDatabaseCount('wan_config_templates', 0);
    }

    /**
     * Kebalikannya — Template yang SUDAH ada tetap utuh begitu Tipe
     * Modem-nya kemudian soft-deleted (bukan request baru, record lama).
     */
    public function test_a_pre_existing_template_survives_its_modem_types_later_soft_delete(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        $template = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'modem_type_id' => $modemType->id]);

        $modemType->delete();

        $this->assertDatabaseHas('wan_config_templates', ['id' => $template->id, 'modem_type_id' => $modemType->id]);
    }

    // ================= CRUD Tipe Modem =================

    public function test_creating_a_modem_type_with_match_patterns(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->set('modemTypeName', 'ZTE F609 Dual-Band')
            ->set('manufacturerMatchPatterns', 'ZICG, CIOT')
            ->call('saveModemType')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('modem_types', [
            'tenant_id' => $tenant->id,
            'name' => 'ZTE F609 Dual-Band',
            'manufacturer_match_patterns' => 'ZICG, CIOT',
            'is_active' => true,
        ]);
    }

    public function test_creating_a_modem_type_with_a_duplicate_name_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        ModemType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'ZTE F609 Dual-Band']);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->set('modemTypeName', 'ZTE F609 Dual-Band')
            ->call('saveModemType')
            ->assertHasErrors('modemTypeName');

        $this->assertSame(1, ModemType::where('name', 'ZTE F609 Dual-Band')->count());
    }

    public function test_a_modem_type_name_can_be_reused_after_the_original_is_soft_deleted(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $old = ModemType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'ZTE F609 Dual-Band']);
        $old->delete();

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->set('modemTypeName', 'ZTE F609 Dual-Band')
            ->call('saveModemType')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('modem_types', ['tenant_id' => $tenant->id, 'name' => 'ZTE F609 Dual-Band', 'deleted_at' => null]);
    }

    public function test_editing_a_modem_type(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Nama Lama']);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('editModemType', $modemType->id)
            ->set('modemTypeName', 'Nama Baru')
            ->set('manufacturerMatchPatterns', 'HWTC')
            ->set('modemTypeIsActive', false)
            ->call('saveModemType')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('modem_types', [
            'id' => $modemType->id,
            'name' => 'Nama Baru',
            'manufacturer_match_patterns' => 'HWTC',
            'is_active' => false,
        ]);
    }

    public function test_deleting_an_unused_modem_type(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('deleteModemType', $modemType->id)
            ->assertHasNoErrors();

        $this->assertSoftDeleted('modem_types', ['id' => $modemType->id]);
    }

    public function test_deleting_a_modem_type_still_used_by_a_template_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'modem_type_id' => $modemType->id]);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('deleteModemType', $modemType->id)
            ->assertHasErrors('modemTypeDelete');

        $this->assertDatabaseHas('modem_types', ['id' => $modemType->id, 'deleted_at' => null]);
    }

    // ================= Delete Template =================

    public function test_deleting_a_template(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $template = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('deleteTemplate', $template->id);

        $this->assertDatabaseMissing('wan_config_templates', ['id' => $template->id]);
    }
}
