<?php

namespace Tests\Feature\Staff;

use App\Enums\ReferrerType;
use App\Enums\WorkOrderStatus;
use App\Livewire\Staff\StaffIndex;
use App\Models\Customer;
use App\Models\Referrer;
use App\Models\Reseller;
use App\Models\ResellerUser;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\WhatsappPhone;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class StaffIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // v0.22.4 — lihat komentar sama di StaffServiceTest::setUp() —
        // tanpa Bus::fake() ini, createStaff() lewat Livewire akan
        // benar-benar menjalankan SendWhatsappMessageJob secara sinkron
        // (QUEUE_CONNECTION test = 'sync'), sleep 5-10 detik x2 per
        // panggilan.
        Bus::fake();
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    public static function allRoles(): array
    {
        return [
            'superadmin' => ['superadmin'],
            'administrator' => ['administrator'],
            'noc' => ['noc'],
            'customer_service' => ['customer_service'],
            'teknisi' => ['teknisi'],
            'billing' => ['billing'],
            'sales_internal' => ['sales_internal'],
            'sales_freelance' => ['sales_freelance'],
            'finance' => ['finance'],
        ];
    }

    /**
     * @dataProvider allRoles
     */
    public function test_creating_a_staff_account_works_for_every_selectable_role(string $role): void
    {
        $tenant = Tenant::factory()->create();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(StaffIndex::class)
            ->set('name', "Staff {$role}")
            ->set('email', "staff-{$role}@boss.local")
            ->set('phone', '081234500000')
            ->set('role', $role)
            ->call('createStaff')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => "staff-{$role}@boss.local",
            'tenant_id' => $tenant->id,
        ]);

        $created = User::where('email', "staff-{$role}@boss.local")->firstOrFail();
        $this->assertTrue($created->hasRole($role));

        // Password acak yang di-generate harus muncul sekali di layar.
        $component->assertSet('generatedPasswordForName', "Staff {$role}");
        $this->assertNotNull($component->get('generatedPassword'));
    }

    public function test_role_is_required_when_creating_a_staff_account(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(StaffIndex::class)
            ->set('name', 'Tanpa Role')
            ->set('email', 'tanpa-role@boss.local')
            ->set('phone', '081234500001')
            ->set('role', '')
            ->call('createStaff')
            ->assertHasErrors(['role' => 'required']);

        $this->assertDatabaseMissing('users', ['email' => 'tanpa-role@boss.local']);
    }

    /**
     * v0.22.2 — email jadi opsional. Staff tetap bisa dibuat tanpa email,
     * login sepenuhnya lewat nomor HP.
     */
    public function test_creating_a_staff_account_without_email_succeeds(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(StaffIndex::class)
            ->set('name', 'Tanpa Email')
            ->set('email', '')
            ->set('phone', '081234500002')
            ->set('role', 'noc')
            ->call('createStaff')
            ->assertHasNoErrors();

        $created = User::where('name', 'Tanpa Email')->firstOrFail();
        $this->assertNull($created->email);
        $this->assertTrue($created->hasRole('noc'));
    }

    /**
     * v0.22.2 — phone jadi WAJIB (alat login utama), berbeda dari v0.22.1
     * yang dulu opsional. Validasi 'required' di layer Livewire.
     */
    public function test_creating_a_staff_account_without_phone_is_rejected_by_validation(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(StaffIndex::class)
            ->set('name', 'Tanpa HP')
            ->set('email', 'tanpa-hp@boss.local')
            ->set('phone', '')
            ->set('role', 'noc')
            ->call('createStaff')
            ->assertHasErrors(['phone' => 'required']);

        $this->assertDatabaseMissing('users', ['email' => 'tanpa-hp@boss.local']);
    }

    public function test_access_is_denied_for_a_role_other_than_superadmin_or_administrator(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        Livewire::actingAs($user)->test(StaffIndex::class)->assertForbidden();
    }

    public function test_disabling_then_enabling_a_staff_account_toggles_the_flag(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant->id]);
        $staff->assignRole('noc');

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->call('toggleDisable', $staff->id);

        $this->assertTrue($staff->fresh()->is_disabled);

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->call('toggleDisable', $staff->id);

        $this->assertFalse($staff->fresh()->is_disabled);
    }

    public function test_editing_a_staff_account_changes_role_without_touching_the_password(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'edit-me@boss.local']);
        $staff->assignRole('billing');
        $originalHash = $staff->password;

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->call('edit', $staff->id)
            ->assertSet('editRole', 'billing')
            ->set('editName', 'Staff Diedit')
            ->set('editEmail', 'edit-me@boss.local')
            ->set('editRole', 'finance')
            ->call('updateStaff')
            ->assertHasNoErrors();

        $fresh = $staff->fresh();
        $this->assertSame('Staff Diedit', $fresh->name);
        $this->assertTrue($fresh->hasRole('finance'));
        $this->assertFalse($fresh->hasRole('billing'));
        $this->assertSame($originalHash, $fresh->password);
    }

    public function test_a_disabled_staff_user_still_shows_up_in_the_list_with_the_disabled_badge(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Staff Disabled', 'is_disabled' => true]);
        $staff->assignRole('teknisi');

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->assertSee('Staff Disabled')
            ->assertSee('Disabled');
    }

    public function test_deleting_a_staff_account_without_relations_succeeds(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant->id]);
        $staff->assignRole('billing');

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->call('confirmDeleteStaff', $staff->id)
            ->set('deleteConfirmationInput', $staff->name)
            ->call('deleteStaff')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }

    public function test_deleting_a_staff_account_that_still_has_a_reseller_membership_shows_the_error_instead_of_deleting(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Reseller Uji']);
        $staff = User::factory()->create(['tenant_id' => $tenant->id]);
        $staff->assignRole('sales_internal');
        ResellerUser::create([
            'reseller_id' => $reseller->id,
            'user_id' => $staff->id,
            'role' => 'staff',
            'status' => 'active',
        ]);

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->call('confirmDeleteStaff', $staff->id)
            ->set('deleteConfirmationInput', $staff->name)
            ->call('deleteStaff')
            ->assertHasErrors(['deleteStaff']);

        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

    /**
     * Root cause insiden Kamisem (2026-09-15) — akun Referrer portal
     * (zero-role by design) dulu ikut muncul di "Manajemen Staff" tanpa
     * tanda visual apa pun, sampai akhirnya terhapus tanpa disadari itu
     * akun produksi. `whereHas('roles')` menutup ini secara struktural:
     * halaman ini tidak pernah lagi bisa menampilkan (apalagi
     * menawarkan tombol Hapus untuk) akun zero-role.
     */
    public function test_a_zero_role_referrer_portal_account_never_appears_in_the_staff_list(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $referrerAccount = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Akun Referrer Portal Tanpa Role']);
        // Sengaja TIDAK assignRole() apa pun — mereplikasi persis
        // ReferrerService::attachNewLoginAccount() (zero-role by design).

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->assertDontSee('Akun Referrer Portal Tanpa Role');

        $this->assertDatabaseHas('users', ['id' => $referrerAccount->id]);
    }

    public function test_deleting_a_staff_account_that_is_still_an_assigned_technician_shows_the_error_instead_of_deleting(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant->id]);
        $staff->assignRole('teknisi');
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $staff->id]);
        WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'technician_id' => $technician->id, 'status' => WorkOrderStatus::Assigned]);

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->call('confirmDeleteStaff', $staff->id)
            ->set('deleteConfirmationInput', $staff->name)
            ->call('deleteStaff')
            ->assertHasErrors(['deleteStaff']);

        $this->assertDatabaseHas('users', ['id' => $staff->id]);
        $this->assertDatabaseHas('technicians', ['id' => $technician->id]);
    }

    // ═══════════════════════════════════════════════════════════════
    // v0.22.3 — checkbox "Jadikan juga Referrer"
    // ═══════════════════════════════════════════════════════════════

    public static function referrerEligibleRoles(): array
    {
        return [
            'sales_internal' => ['sales_internal'],
            'sales_freelance' => ['sales_freelance'],
            'customer_service' => ['customer_service'],
            'teknisi' => ['teknisi'],
        ];
    }

    public static function referrerIneligibleRoles(): array
    {
        return [
            'superadmin' => ['superadmin'],
            'administrator' => ['administrator'],
            'noc' => ['noc'],
            'billing' => ['billing'],
            'finance' => ['finance'],
        ];
    }

    /**
     * @dataProvider referrerEligibleRoles
     */
    public function test_referrer_checkbox_is_visible_for_eligible_roles(string $role): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(StaffIndex::class)
            ->set('showCreateForm', true)
            ->set('role', $role)
            ->assertSee('Jadikan juga Referrer');
    }

    /**
     * @dataProvider referrerIneligibleRoles
     */
    public function test_referrer_checkbox_is_not_visible_for_ineligible_roles(string $role): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(StaffIndex::class)
            ->set('showCreateForm', true)
            ->set('role', $role)
            ->assertDontSee('Jadikan juga Referrer');
    }

    /**
     * Ganti role dari eligible ke tidak-eligible (mis. sudah sempat
     * dicentang lalu ganti pikiran soal role) harus mereset checkbox +
     * tipe-nya secara otomatis (`updatedRole()`) — bukan cuma
     * disembunyikan di UI sementara nilainya masih "nyangkut" di server.
     */
    public function test_changing_role_away_from_an_eligible_role_resets_the_referrer_checkbox(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(StaffIndex::class)
            ->set('role', 'sales_internal')
            ->set('wantsReferrer', true)
            ->assertSet('referrerType', 'sales')
            ->set('role', 'billing')
            ->assertSet('wantsReferrer', false)
            ->assertSet('referrerType', '');
    }

    public function test_creating_a_staff_with_the_referrer_checkbox_checked_creates_and_links_a_referrer(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(StaffIndex::class)
            ->set('name', 'Staff Sales Referrer')
            ->set('phone', '081234611111')
            ->set('role', 'sales_internal')
            ->set('wantsReferrer', true)
            ->assertSet('referrerType', 'sales')
            ->call('createStaff')
            ->assertHasNoErrors();

        $staff = User::where('name', 'Staff Sales Referrer')->firstOrFail();
        $referrer = Referrer::withoutGlobalScopes()->where('user_id', $staff->id)->first();

        $this->assertNotNull($referrer);
        $this->assertSame(ReferrerType::Sales, $referrer->type);
        $this->assertTrue($referrer->is_active);
    }

    /**
     * customer_service tidak punya padanan `ReferrerType` otomatis — admin
     * WAJIB pilih manual, submit tanpa memilih harus ditolak validasi.
     */
    public function test_referrer_type_is_required_when_the_checkbox_is_checked_for_customer_service(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(StaffIndex::class)
            ->set('name', 'Staff CS Referrer')
            ->set('phone', '081234622222')
            ->set('role', 'customer_service')
            ->set('wantsReferrer', true)
            ->assertSet('referrerType', '')
            ->call('createStaff')
            ->assertHasErrors(['referrerType' => 'required']);

        $this->assertDatabaseMissing('users', ['name' => 'Staff CS Referrer']);
    }

    /**
     * Checkbox tidak dicentang (default) — staff dibuat seperti biasa,
     * tidak ada Referrer sama sekali, tidak ada validasi referrerType yang
     * ikut memblokir.
     */
    public function test_creating_a_staff_without_checking_the_referrer_checkbox_creates_no_referrer(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(StaffIndex::class)
            ->set('name', 'Staff Sales Biasa')
            ->set('phone', '081234633333')
            ->set('role', 'sales_internal')
            ->call('createStaff')
            ->assertHasNoErrors();

        $staff = User::where('name', 'Staff Sales Biasa')->firstOrFail();
        $this->assertSame(0, Referrer::withoutGlobalScopes()->where('user_id', $staff->id)->count());
    }

    public function test_the_referrer_column_shows_active_for_a_linked_staff_and_dash_otherwise(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        $linkedStaff = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Staff Ber-Referrer']);
        $linkedStaff->assignRole('teknisi');
        Referrer::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $linkedStaff->id, 'is_active' => true]);

        $plainStaff = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Staff Polos']);
        $plainStaff->assignRole('noc');

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->assertSeeInOrder(['Staff Ber-Referrer', 'Ya, aktif'])
            ->assertSee('Staff Polos');
    }

    /**
     * Disable/enable staff yang punya Referrer ter-link — Referrer ikut
     * cascade status-nya, dieksekusi lewat jalur Livewire penuh (bukan
     * cuma level service, sudah dites di `StaffServiceTest`).
     */
    public function test_disabling_a_staff_with_a_linked_referrer_deactivates_it_and_enabling_reactivates_it(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant->id]);
        $staff->assignRole('teknisi');
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $staff->id, 'is_active' => true]);

        Livewire::actingAs($admin)->test(StaffIndex::class)->call('toggleDisable', $staff->id);
        $this->assertFalse($referrer->fresh()->is_active);

        Livewire::actingAs($admin)->test(StaffIndex::class)->call('toggleDisable', $staff->id);
        $this->assertTrue($referrer->fresh()->is_active);
    }

    /**
     * Delete staff dengan Referrer ter-link tetap boleh (tidak diblokir) —
     * Referrer-nya tetap ada, cuma `user_id` jadi null.
     */
    public function test_deleting_a_staff_with_a_linked_referrer_succeeds_and_leaves_the_referrer_with_a_null_user_id(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant->id]);
        $staff->assignRole('sales_freelance');
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $staff->id]);

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->call('confirmDeleteStaff', $staff->id)
            ->set('deleteConfirmationInput', $staff->name)
            ->call('deleteStaff')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
        $this->assertDatabaseHas('referrers', ['id' => $referrer->id, 'user_id' => null]);
    }

    /**
     * v0.22.8 (REVISI) — collision ke Referrer ORPHAN DAN genuinely kosong:
     * row lama dihapus permanen di background (ReferrerService), staff
     * dapat Referrer BARU yang fresh — tanpa tombol/aksi manual apa pun.
     * Badge "Referrer?" langsung "Ya, aktif" begitu createStaff() selesai.
     */
    public function test_creating_a_staff_with_a_phone_collision_to_an_empty_orphan_referrer_auto_replaces_it(): void
    {
        $tenant = Tenant::factory()->create();
        $normalizedPhone = WhatsappPhone::normalize('081234655555');
        $oldReferrer = Referrer::factory()->create([
            'tenant_id' => $tenant->id,
            'phone' => $normalizedPhone,
            'user_id' => null,
            'name' => 'Referrer Orphan Lama',
        ]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(StaffIndex::class)
            ->set('name', 'Staff Collision Orphan')
            ->set('phone', '081234655555')
            ->set('role', 'sales_internal')
            ->set('wantsReferrer', true)
            ->call('createStaff')
            ->assertHasNoErrors()
            ->assertSet('referrerLinkFailed', false)
            ->assertDontSee('Link ke Referrer lama ini');

        $staff = User::where('name', 'Staff Collision Orphan')->firstOrFail();

        // Row lama benar-benar hilang; staff ter-link ke Referrer BARU.
        $this->assertDatabaseMissing('referrers', ['id' => $oldReferrer->id]);
        $this->assertDatabaseHas('referrers', ['user_id' => $staff->id, 'name' => 'Staff Collision Orphan']);

        // Badge "Referrer?" langsung "Ya, aktif" — tidak perlu aksi manual.
        $component->assertSee('Ya, aktif');
    }

    /**
     * v0.22.8 (REVISI) — collision ke Referrer ORPHAN TAPI berisi data
     * nyantol: hard block, pesan generik, row lama TIDAK terhapus.
     */
    public function test_creating_a_staff_with_a_phone_collision_to_an_orphan_referrer_with_linked_data_hard_blocks(): void
    {
        $tenant = Tenant::factory()->create();
        $normalizedPhone = WhatsappPhone::normalize('081234677777');
        $oldReferrer = Referrer::factory()->create([
            'tenant_id' => $tenant->id,
            'phone' => $normalizedPhone,
            'user_id' => null,
        ]);
        Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'referred_by_referrer_id' => $oldReferrer->id,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(StaffIndex::class)
            ->set('name', 'Staff Collision Orphan Berisi Data')
            ->set('phone', '081234677777')
            ->set('role', 'sales_internal')
            ->set('wantsReferrer', true)
            ->call('createStaff')
            ->assertHasNoErrors()
            ->assertSet('referrerLinkFailed', true)
            ->assertSee('sudah pernah dipakai')
            ->assertDontSee('Link ke Referrer lama ini');

        $this->assertDatabaseHas('users', ['name' => 'Staff Collision Orphan Berisi Data']);
        $this->assertDatabaseHas('referrers', ['id' => $oldReferrer->id]);
    }

    /**
     * v0.22.8 — collision ke Referrer yang SUDAH terhubung ke user lain
     * (masih aktif): hard block seperti sebelumnya, tanpa pengecualian.
     */
    public function test_creating_a_staff_with_a_phone_collision_to_a_referrer_already_linked_to_another_user_hard_blocks(): void
    {
        $tenant = Tenant::factory()->create();
        $normalizedPhone = WhatsappPhone::normalize('081234666666');
        $otherStaff = User::factory()->create(['tenant_id' => $tenant->id]);
        $takenReferrer = Referrer::factory()->create([
            'tenant_id' => $tenant->id,
            'phone' => $normalizedPhone,
            'user_id' => $otherStaff->id,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(StaffIndex::class)
            ->set('name', 'Staff Collision Taken')
            ->set('phone', '081234666666')
            ->set('role', 'sales_internal')
            ->set('wantsReferrer', true)
            ->call('createStaff')
            ->assertHasNoErrors()
            ->assertSet('referrerLinkFailed', true)
            ->assertSee('sudah pernah dipakai')
            ->assertDontSee('Link ke Referrer lama ini');

        // Staff-nya SENDIRI tetap berhasil dibuat.
        $this->assertDatabaseHas('users', ['name' => 'Staff Collision Taken']);

        // Referrer yang taken TIDAK berubah sama sekali.
        $this->assertDatabaseHas('referrers', ['id' => $takenReferrer->id, 'user_id' => $otherStaff->id]);
    }

    // ═══════════════════════════════════════════════════════════════
    // v0.22.8 (revisi Bagian B) — modal konfirmasi hapus ketik-nama
    // ═══════════════════════════════════════════════════════════════

    public function test_delete_confirmation_modal_appears_when_clicking_hapus(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Staff Akan Dihapus']);
        $staff->assignRole('billing');

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->call('confirmDeleteStaff', $staff->id)
            ->assertSet('deletingUserId', $staff->id)
            ->assertSet('deletingUserName', 'Staff Akan Dihapus')
            ->assertSee('Staff Akan Dihapus')
            ->assertSee('Hapus Staff Permanen');

        // Belum ada yang terhapus hanya karena modal terbuka.
        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

    public function test_delete_button_stays_disabled_and_nothing_is_deleted_when_the_typed_name_does_not_match(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Staff Akan Dihapus']);
        $staff->assignRole('billing');

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->call('confirmDeleteStaff', $staff->id)
            ->set('deleteConfirmationInput', 'nama salah ketik')
            ->call('deleteStaff')
            ->assertHasErrors(['deleteConfirmationInput'])
            // Modal TETAP terbuka — bukan silent no-op.
            ->assertSet('deletingUserId', $staff->id);

        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

    public function test_delete_button_enabled_and_deleting_succeeds_when_the_typed_name_matches_exactly(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Staff Akan Dihapus']);
        $staff->assignRole('billing');

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->call('confirmDeleteStaff', $staff->id)
            ->set('deleteConfirmationInput', 'Staff Akan Dihapus')
            ->call('deleteStaff')
            ->assertHasNoErrors()
            ->assertSet('deletingUserId', null);

        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }

    public function test_canceling_the_delete_modal_deletes_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Staff Akan Dihapus']);
        $staff->assignRole('billing');

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->call('confirmDeleteStaff', $staff->id)
            ->set('deleteConfirmationInput', 'Staff Akan Dihapus')
            ->call('cancelDeleteStaff')
            ->assertSet('deletingUserId', null)
            ->assertSet('deleteConfirmationInput', '');

        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

    /**
     * Modal berlaku SAMA untuk semua staff — termasuk yang juga Referrer
     * aktif — dengan info tambahan (bukan pengecualian mekanisme).
     */
    public function test_delete_confirmation_modal_shows_the_active_referrer_notice_when_relevant(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Staff Ber-Referrer']);
        $staff->assignRole('sales_freelance');
        Referrer::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $staff->id, 'is_active' => true]);

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->call('confirmDeleteStaff', $staff->id)
            ->assertSet('deletingUserHasActiveReferrer', true)
            ->assertSee('Staff ini juga Referrer aktif');
    }
}
