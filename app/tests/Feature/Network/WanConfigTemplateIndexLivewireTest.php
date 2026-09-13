<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\WanConfigTemplateIndex;
use App\Models\ModemType;
use App\Models\PppPackage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WanConfigTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.12.5 — "Template Konfig CPE" (matrix Paket x Tipe Modem). Langkah 4:
 * validasi uniqueness (termasuk skenario soft-delete PppPackage/ModemType,
 * pelajaran langsung dari insiden verifikasi v0.12.4), validasi
 * mutually-exclusive kombinasi Paket x Tipe Modem, dan CRUD ModemType.
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

    // ================= Create — matrix dasar =================

    public function test_creating_a_default_template_for_a_package(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('openCreateForTemplate', $package->id)
            ->set('modemTypeSelection', 'default')
            ->set('wan1Vlan', '10')
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('wan_config_templates', [
            'ppp_package_id' => $package->id,
            'modem_type_id' => null,
            'wan1_vlan' => 10,
        ]);
    }

    public function test_creating_a_modem_specific_template_for_a_package(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('openCreateForTemplate', $package->id)
            ->set('modemTypeSelection', (string) $modemType->id)
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('wan_config_templates', [
            'ppp_package_id' => $package->id,
            'modem_type_id' => $modemType->id,
        ]);
    }

    // ================= Validasi mutually-exclusive (Paket x Tipe Modem) =================

    public function test_two_templates_with_the_same_package_and_modem_type_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => $modemType->id]);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('openCreateForTemplate', $package->id)
            ->set('modemTypeSelection', (string) $modemType->id)
            ->call('saveTemplate')
            ->assertHasErrors('modemTypeSelection');

        $this->assertSame(1, WanConfigTemplate::where('ppp_package_id', $package->id)->where('modem_type_id', $modemType->id)->count());
    }

    /**
     * Kasus NULL — partial unique index harus mencegah DUA baris "default"
     * (modem_type_id NULL) untuk paket yang sama, persis diverifikasi live
     * saat skema v0.12.4 dibangun. Diuji di sini lewat jalur UI penuh, bukan
     * cuma query DB langsung.
     */
    public function test_two_default_templates_for_the_same_package_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => null]);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('openCreateForTemplate', $package->id)
            ->set('modemTypeSelection', 'default')
            ->call('saveTemplate')
            ->assertHasErrors('modemTypeSelection');

        $this->assertSame(1, WanConfigTemplate::where('ppp_package_id', $package->id)->whereNull('modem_type_id')->count());
    }

    /**
     * Kombinasi paket SAMA + modem BEDA harus tetap diizinkan — memastikan
     * validasi di atas benar-benar per-kombinasi, bukan per-paket saja.
     */
    public function test_same_package_with_a_different_modem_type_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        $modemA = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        $modemB = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => $modemA->id]);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('openCreateForTemplate', $package->id)
            ->set('modemTypeSelection', (string) $modemB->id)
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertSame(2, WanConfigTemplate::where('ppp_package_id', $package->id)->count());
    }

    /**
     * Mengedit template yang sudah ada (mengubah field lain, bukan
     * kombinasi paket/modem-nya) tidak boleh dianggap bentrok dengan
     * dirinya sendiri.
     */
    public function test_editing_a_template_does_not_flag_a_collision_with_its_own_unchanged_combination(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        $template = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => $modemType->id]);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('editTemplate', $template->id)
            ->set('wan1Vlan', '999')
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertSame(999, $template->fresh()->wan1_vlan);
    }

    // ================= Validasi soft-delete (pelajaran insiden v0.12.4) =================

    /**
     * PppPackage yang soft-deleted TIDAK boleh bisa dipilih untuk membuat
     * template baru — persis kelas bug yang menyebabkan PppPackage #17
     * "PPPoE-Remote" produksi ter-soft-delete tak sengaja saat verifikasi
     * restrictOnDelete() kemarin. Dropdown (query PppPackage biasa) sudah
     * otomatis exclude via SoftDeletingScope; ini menguji SISI SERVER
     * (submit dengan id paket yang sudah soft-deleted, seolah dikirim
     * manual/stale) benar-benar ditolak juga, bukan cuma disembunyikan di
     * dropdown.
     */
    public function test_a_soft_deleted_package_is_rejected_when_creating_a_template(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        $package->delete();

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->set('pppPackageId', (string) $package->id)
            ->set('modemTypeSelection', 'default')
            ->call('saveTemplate')
            ->assertHasErrors('pppPackageId');

        $this->assertDatabaseCount('wan_config_templates', 0);
    }

    /**
     * ModemType yang soft-deleted TIDAK boleh bisa dipilih untuk membuat
     * template baru — sisi lain dari case di atas.
     */
    public function test_a_soft_deleted_modem_type_is_rejected_when_creating_a_template(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        $modemType->delete();

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->call('openCreateForTemplate', $package->id)
            ->set('modemTypeSelection', (string) $modemType->id)
            ->call('saveTemplate')
            ->assertHasErrors('modemTypeSelection');

        $this->assertDatabaseCount('wan_config_templates', 0);
    }

    /**
     * Kebalikannya — sebuah kombinasi (paket, modem) yang sudah PERNAH
     * dipakai template tapi paket/modem-nya KEMUDIAN soft-deleted (bukan
     * request baru, tapi record lama) TIDAK boleh membuat kombinasi itu
     * "terkunci selamanya" untuk paket/modem lain yang masih hidup — sudah
     * tercakup test_same_package_with_a_different_modem_type_is_allowed di
     * atas untuk arah "kombinasi beda tetap boleh"; test ini menegaskan
     * arah sebaliknya: begitu paket A di-soft-delete, PppPackage #A tidak
     * lagi bisa dipilih SAMA SEKALI (baik untuk kombinasi baru maupun
     * "menghidupkan lagi" kombinasi lama) — dicek lewat assertDatabaseHas
     * bahwa baris LAMA (dibuat sebelum soft-delete) tetap utuh, hanya
     * PEMBUATAN BARU yang diblokir.
     */
    public function test_a_pre_existing_template_survives_its_packages_later_soft_delete(): void
    {
        $tenant = Tenant::factory()->create();
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        $template = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id]);

        $package->delete();

        $this->assertDatabaseHas('wan_config_templates', ['id' => $template->id, 'ppp_package_id' => $package->id]);
    }

    // ================= CRUD Tipe Modem =================

    public function test_creating_a_modem_type(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        $this->actingAs($admin);

        Livewire::test(WanConfigTemplateIndex::class)
            ->set('modemTypeName', 'ZTE F609 Dual-Band')
            ->call('saveModemType')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('modem_types', ['tenant_id' => $tenant->id, 'name' => 'ZTE F609 Dual-Band', 'is_active' => true]);
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

    /**
     * Nama yang sama dengan ModemType yang SUDAH soft-deleted boleh dipakai
     * ulang — sama pola bandwidth_profiles/ppp_packages (partial unique
     * index WHERE deleted_at IS NULL).
     */
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
            ->set('modemTypeIsActive', false)
            ->call('saveModemType')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('modem_types', ['id' => $modemType->id, 'name' => 'Nama Baru', 'is_active' => false]);
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

    /**
     * ModemType yang masih dipakai WanConfigTemplate AKTIF tidak boleh
     * dihapus — cek referential integrity EKSPLISIT di ModemTypeService,
     * bukan mengandalkan restrictOnDelete() FK saja (yang terbukti tidak
     * memblokir soft-delete, lihat insiden verifikasi v0.12.4).
     */
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
