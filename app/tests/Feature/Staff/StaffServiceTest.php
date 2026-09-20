<?php

namespace Tests\Feature\Staff;

use App\Enums\ReferrerType;
use App\Enums\WhatsappEventType;
use App\Enums\WorkOrderStatus;
use App\Models\CpeActionLog;
use App\Models\Customer;
use App\Models\Referrer;
use App\Models\Reseller;
use App\Models\ResellerUser;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WorkOrder;
use App\Services\StaffService;
use App\Support\WhatsappPhone;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.22.1 — CRUD Staff (Manajemen User), service-level. Semua 9 role Spatie
 * harus bisa dipilih (termasuk superadmin, single-choice) — dikunci
 * eksplisit di kickoff.
 */
class StaffServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // v0.22.4 — StaffService::create() sekarang selalu mengantre 2
        // pesan WA (password awal). QUEUE_CONNECTION test = 'sync' (lihat
        // phpunit.xml) — TANPA Bus::fake() ini, SendWhatsappMessageJob
        // benar-benar dieksekusi sinkron per panggilan create(), termasuk
        // applyRateLimitDelay()'s sleep(5-10 detik) x2 pesan — akan
        // membuat SELURUH file test ini (puluhan panggilan create())
        // sangat lambat tanpa guna (ditemukan nyata: satu jalan test suite
        // scoped v0.22.4 sempat menggantung >120s persis karena ini).
        // Test yang BENAR-BENAR ingin memverifikasi WA tetap bisa
        // Bus::assertDispatched(SendWhatsappMessageJob::class) — baris
        // WhatsappMessageLog sendiri tetap tercipta sebelum dispatch,
        // assertDatabaseHas() tetap valid dengan fake ini.
        Bus::fake();
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
    public function test_create_assigns_the_given_role_including_superadmin(string $role): void
    {
        $tenant = Tenant::factory()->create();

        $result = (new StaffService)->create([
            'name' => 'Staff Test',
            'email' => "staff-{$role}@boss.local",
            'phone' => '081234567890',
            'role' => $role,
            'tenant_id' => $tenant->id,
        ]);

        $user = $result['user'];

        $this->assertTrue($user->hasRole($role));
        $this->assertCount(1, $user->roles);
        $this->assertSame($tenant->id, $user->tenant_id);
        // v0.22.2 — phone dinormalisasi WhatsappPhone::normalize() saat
        // simpan (alat login utama). '081234567890' -> '6281234567890'.
        $this->assertSame(WhatsappPhone::normalize('081234567890'), $user->phone);
        $this->assertFalse($user->is_disabled);
        $this->assertNotEmpty($result['generated_password']);
        $this->assertTrue(Hash::check($result['generated_password'], $user->password));
    }

    /**
     * v0.22.6 — password di sini SELALU random-generated, jadi WAJIB
     * dipaksa ganti saat login pertama (App\Http\Middleware\
     * EnsurePasswordChanged).
     */
    public function test_create_sets_must_change_password_to_true(): void
    {
        $tenant = Tenant::factory()->create();

        $result = (new StaffService)->create([
            'name' => 'Staff Baru',
            'phone' => '081234567891',
            'role' => 'noc',
            'tenant_id' => $tenant->id,
        ]);

        $this->assertTrue($result['user']->must_change_password);
    }

    /**
     * v0.22.2 — email jadi opsional (dulu wajib), phone jadi wajib (dulu
     * opsional) — kebalikan dari kondisi lama yang diuji test ini sebelum
     * direvisi (dulu bernama test_create_allows_a_null_phone).
     */
    public function test_create_allows_a_null_email(): void
    {
        $tenant = Tenant::factory()->create();

        $result = (new StaffService)->create([
            'name' => 'Tanpa Email',
            'phone' => '081234500000',
            'role' => 'noc',
            'tenant_id' => $tenant->id,
        ]);

        $user = $result['user'];

        $this->assertNull($user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(WhatsappPhone::normalize('081234500000'), $user->phone);
    }

    public function test_create_never_persists_the_generated_password_in_plaintext_anywhere_else(): void
    {
        $tenant = Tenant::factory()->create();

        $result = (new StaffService)->create([
            'name' => 'Staff Test',
            'email' => 'plaintext-check@boss.local',
            'phone' => '081200000001',
            'role' => 'noc',
            'tenant_id' => $tenant->id,
        ]);

        $raw = User::query()->find($result['user']->id);

        $this->assertNotSame($result['generated_password'], $raw->password);
    }

    public function test_update_replaces_name_email_and_role_without_touching_the_password(): void
    {
        $tenant = Tenant::factory()->create();
        $result = (new StaffService)->create([
            'name' => 'Before',
            'email' => 'before@boss.local',
            'phone' => '081200000002',
            'role' => 'customer_service',
            'tenant_id' => $tenant->id,
        ]);
        $user = $result['user'];
        $originalHash = $user->password;

        $updated = (new StaffService)->update($user, [
            'name' => 'After',
            'email' => 'after@boss.local',
            'phone' => '089900001111',
            'role' => 'billing',
        ]);

        $this->assertSame('After', $updated->name);
        $this->assertSame('after@boss.local', $updated->email);
        // v0.22.2 — update() juga menormalisasi phone saat simpan.
        $this->assertSame(WhatsappPhone::normalize('089900001111'), $updated->phone);
        $this->assertTrue($updated->hasRole('billing'));
        $this->assertFalse($updated->hasRole('customer_service'));
        $this->assertCount(1, $updated->roles);
        $this->assertSame($originalHash, $updated->password);
    }

    public function test_disable_sets_the_flag_and_enable_clears_it(): void
    {
        $tenant = Tenant::factory()->create();
        $result = (new StaffService)->create([
            'name' => 'Toggle Test',
            'email' => 'toggle@boss.local',
            'phone' => '081200000003',
            'role' => 'finance',
            'tenant_id' => $tenant->id,
        ]);
        $user = $result['user'];

        $disabled = (new StaffService)->disable($user);
        $this->assertTrue($disabled->is_disabled);

        $enabled = (new StaffService)->enable($disabled);
        $this->assertFalse($enabled->is_disabled);
    }

    public function test_delete_removes_the_user_when_there_are_no_blocking_relations(): void
    {
        $tenant = Tenant::factory()->create();
        $result = (new StaffService)->create([
            'name' => 'Bersih',
            'email' => 'bersih@boss.local',
            'phone' => '081200000004',
            'role' => 'finance',
            'tenant_id' => $tenant->id,
        ]);
        $user = $result['user'];

        (new StaffService)->delete($user);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_delete_is_blocked_when_the_staff_still_has_a_reseller_membership(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Reseller Contoh']);
        $result = (new StaffService)->create([
            'name' => 'Anggota Reseller',
            'email' => 'anggota-reseller@boss.local',
            'phone' => '081200000005',
            'role' => 'sales_internal',
            'tenant_id' => $tenant->id,
        ]);
        $user = $result['user'];
        ResellerUser::create([
            'reseller_id' => $reseller->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        try {
            (new StaffService)->delete($user);
            $this->fail('Delete seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Reseller Contoh', $e->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('reseller_users', ['user_id' => $user->id]);
    }

    public function test_delete_is_blocked_with_a_specific_count_when_the_staff_is_a_technician_with_active_work_orders(): void
    {
        $tenant = Tenant::factory()->create();
        $result = (new StaffService)->create([
            'name' => 'Teknisi Sibuk',
            'email' => 'teknisi-sibuk@boss.local',
            'phone' => '081200000006',
            'role' => 'teknisi',
            'tenant_id' => $tenant->id,
        ]);
        $user = $result['user'];
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);
        WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'technician_id' => $technician->id, 'status' => WorkOrderStatus::Assigned]);
        WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'technician_id' => $technician->id, 'status' => WorkOrderStatus::InProgress]);
        // WO selesai — tidak boleh ikut dihitung sebagai "aktif".
        WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'technician_id' => $technician->id, 'status' => WorkOrderStatus::Completed]);

        try {
            (new StaffService)->delete($user);
            $this->fail('Delete seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('2 Work Order aktif', $e->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('technicians', ['id' => $technician->id]);
    }

    public function test_delete_is_blocked_even_without_active_work_orders_when_a_technician_row_still_exists(): void
    {
        $tenant = Tenant::factory()->create();
        $result = (new StaffService)->create([
            'name' => 'Teknisi Lama',
            'email' => 'teknisi-lama@boss.local',
            'phone' => '081200000007',
            'role' => 'teknisi',
            'tenant_id' => $tenant->id,
        ]);
        $user = $result['user'];
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);
        WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'technician_id' => $technician->id, 'status' => WorkOrderStatus::Completed]);

        try {
            (new StaffService)->delete($user);
            $this->fail('Delete seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('akun Teknisi', $e->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('technicians', ['id' => $technician->id]);
    }

    public function test_delete_is_blocked_with_a_count_when_the_staff_has_cpe_action_log_history(): void
    {
        $tenant = Tenant::factory()->create();
        $result = (new StaffService)->create([
            'name' => 'Pernah Aksi CPE',
            'email' => 'aksi-cpe@boss.local',
            'phone' => '081200000008',
            'role' => 'noc',
            'tenant_id' => $tenant->id,
        ]);
        $user = $result['user'];
        CpeActionLog::factory()->create(['tenant_id' => $tenant->id, 'performed_by' => $user->id]);
        CpeActionLog::factory()->create(['tenant_id' => $tenant->id, 'performed_by' => $user->id]);

        try {
            (new StaffService)->delete($user);
            $this->fail('Delete seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('2 aksi perangkat CPE', $e->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    // ═══════════════════════════════════════════════════════════════
    // v0.22.3 — checkbox "Jadikan juga Referrer"
    // ═══════════════════════════════════════════════════════════════

    public static function referrerEligibleRoles(): array
    {
        return [
            'sales_internal' => ['sales_internal', ReferrerType::Sales],
            'sales_freelance' => ['sales_freelance', ReferrerType::Freelance],
            'teknisi' => ['teknisi', ReferrerType::Teknisi],
            // customer_service SENGAJA tidak punya padanan ReferrerType
            // otomatis (dikonfirmasi Agung) — di test ini type dipilih
            // manual, bukan auto-derive.
            'customer_service' => ['customer_service', ReferrerType::Admin],
        ];
    }

    /**
     * @dataProvider referrerEligibleRoles
     */
    public function test_create_with_referrer_data_creates_and_links_a_referrer_for_each_eligible_role(string $role, ReferrerType $type): void
    {
        $tenant = Tenant::factory()->create();

        $result = (new StaffService)->create([
            'name' => 'Staff Referrer',
            'phone' => '081234511111',
            'role' => $role,
            'tenant_id' => $tenant->id,
        ], ['type' => $type->value]);

        $this->assertNotNull($result['referrer']);
        $this->assertNull($result['referrer_link_error']);

        $referrer = $result['referrer'];
        $user = $result['user'];

        $this->assertSame($user->id, $referrer->user_id);
        $this->assertSame($user->name, $referrer->name);
        // Format NORMALIZED (62xxx), konsisten users.phone — keputusan
        // eksplisit kickoff v0.22.3, bukan raw seperti diketik admin.
        $this->assertSame($user->phone, $referrer->phone);
        $this->assertSame($type, $referrer->type);
        $this->assertTrue($referrer->is_active);
        $this->assertSame($tenant->id, $referrer->tenant_id);
    }

    public function test_create_without_referrer_data_creates_no_referrer_at_all(): void
    {
        $tenant = Tenant::factory()->create();

        $result = (new StaffService)->create([
            'name' => 'Staff Biasa',
            'phone' => '081234522222',
            'role' => 'sales_internal',
            'tenant_id' => $tenant->id,
        ]);

        $this->assertNull($result['referrer']);
        $this->assertNull($result['referrer_link_error']);
        $this->assertSame(0, Referrer::withoutGlobalScopes()->count());
    }

    /**
     * Guard collision `(tenant_id, phone)` di `ReferrerService::
     * createAndLinkToStaff()` — dikunci Agung: staff TETAP berhasil dibuat
     * meski link Referrer-nya gagal, pesan errornya jelas (bukan
     * QueryException mentah).
     */
    public function test_create_with_referrer_data_still_creates_the_staff_when_the_referrer_link_fails_on_a_phone_collision(): void
    {
        $tenant = Tenant::factory()->create();
        // Referrer lain YANG SUDAH ADA di tenant yang sama dengan nomor HP
        // yang PERSIS SAMA (setelah dinormalisasi) dengan yang akan dipakai
        // staff baru — belum tentu ter-link ke user manapun.
        Referrer::factory()->create([
            'tenant_id' => $tenant->id,
            'phone' => WhatsappPhone::normalize('081234533333'),
            'user_id' => null,
        ]);

        $result = (new StaffService)->create([
            'name' => 'Staff Bentrok',
            'phone' => '081234533333',
            'role' => 'sales_internal',
            'tenant_id' => $tenant->id,
        ], ['type' => ReferrerType::Sales->value]);

        // Staff-nya SENDIRI tetap berhasil dibuat.
        $this->assertDatabaseHas('users', ['id' => $result['user']->id]);
        $this->assertNotEmpty($result['generated_password']);

        // Link Referrer-nya gagal dengan pesan jelas.
        $this->assertNull($result['referrer']);
        $this->assertNotNull($result['referrer_link_error']);
        $this->assertStringContainsString('sudah ada Referrer lain', $result['referrer_link_error']);

        // Cuma 1 baris Referrer (yang lama) — tidak ada baris baru yang
        // gagal setengah jalan tertinggal.
        $this->assertSame(1, Referrer::withoutGlobalScopes()->count());
    }

    public function test_disable_deactivates_the_linked_referrer(): void
    {
        $tenant = Tenant::factory()->create();
        $result = (new StaffService)->create([
            'name' => 'Staff Referrer Disable',
            'phone' => '081234544444',
            'role' => 'teknisi',
            'tenant_id' => $tenant->id,
        ], ['type' => ReferrerType::Teknisi->value]);
        $this->assertTrue($result['referrer']->is_active);

        (new StaffService)->disable($result['user']);

        $this->assertFalse($result['referrer']->fresh()->is_active);
    }

    public function test_enable_reactivates_the_linked_referrer(): void
    {
        $tenant = Tenant::factory()->create();
        $result = (new StaffService)->create([
            'name' => 'Staff Referrer Enable',
            'phone' => '081234555555',
            'role' => 'teknisi',
            'tenant_id' => $tenant->id,
        ], ['type' => ReferrerType::Teknisi->value]);
        $service = new StaffService;
        $service->disable($result['user']);
        $this->assertFalse($result['referrer']->fresh()->is_active);

        $service->enable($result['user']->fresh());

        $this->assertTrue($result['referrer']->fresh()->is_active);
    }

    /**
     * Disable/enable staff yang TIDAK punya Referrer ter-link tidak boleh
     * error/no-op aneh — `linkedReferrer()` mengembalikan null, cascade
     * dilewati begitu saja.
     */
    public function test_disable_and_enable_are_unaffected_for_a_staff_with_no_linked_referrer(): void
    {
        $tenant = Tenant::factory()->create();
        $result = (new StaffService)->create([
            'name' => 'Staff Tanpa Referrer',
            'phone' => '081234566666',
            'role' => 'noc',
            'tenant_id' => $tenant->id,
        ]);

        $disabled = (new StaffService)->disable($result['user']);
        $this->assertTrue($disabled->is_disabled);

        $enabled = (new StaffService)->enable($disabled);
        $this->assertFalse($enabled->is_disabled);
    }

    /**
     * Delete staff dengan Referrer ter-link → Referrer TETAP ADA (data
     * referral/komisi tidak boleh hilang, pelajaran insiden Kamisem
     * v0.22.1), cuma `user_id`-nya jadi null (via `nullOnDelete()` di
     * level DB, bukan kode aplikasi tambahan). `is_active` Referrer TIDAK
     * ikut berubah oleh delete (beda dari disable — staff dihapus bukan
     * berarti Referrer-nya juga harus nonaktif, itu keputusan admin
     * terpisah).
     */
    public function test_delete_leaves_the_linked_referrer_intact_with_user_id_nulled(): void
    {
        $tenant = Tenant::factory()->create();
        $result = (new StaffService)->create([
            'name' => 'Staff Referrer Delete',
            'phone' => '081234577777',
            'role' => 'sales_freelance',
            'tenant_id' => $tenant->id,
        ], ['type' => ReferrerType::Freelance->value]);
        $referrerId = $result['referrer']->id;

        (new StaffService)->delete($result['user']);

        $this->assertDatabaseMissing('users', ['id' => $result['user']->id]);
        $this->assertDatabaseHas('referrers', ['id' => $referrerId, 'user_id' => null]);
        $this->assertTrue(Referrer::withoutGlobalScopes()->find($referrerId)->is_active);
    }

    /**
     * v0.22.7 — kalau staff yang dihapus JUGA Referrer ter-link, setiap
     * Customer yang mengarah ke Referrer itu genuinely dikosongkan (bukan
     * nyantol ke Referrer yang login-nya sudah hilang) — referral_locked +
     * jejak namanya disimpan.
     */
    public function test_delete_locks_customer_referrals_pointing_to_the_linked_referrer(): void
    {
        $tenant = Tenant::factory()->create();
        $result = (new StaffService)->create([
            'name' => 'Staff Referrer Punya Customer',
            'phone' => '081234566661',
            'role' => 'sales_freelance',
            'tenant_id' => $tenant->id,
        ], ['type' => ReferrerType::Freelance->value]);
        $referrer = $result['referrer'];

        $customerA = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'referred_by_referrer_id' => $referrer->id,
        ]);
        $customerB = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'referred_by_referrer_id' => $referrer->id,
        ]);

        (new StaffService)->delete($result['user']);

        foreach ([$customerA, $customerB] as $customer) {
            $customer->refresh();
            $this->assertTrue($customer->referral_locked);
            $this->assertSame('Staff Referrer Punya Customer', $customer->locked_former_referrer_name);
            $this->assertNull($customer->referred_by_referrer_id);
        }

        // Referrer sendiri TIDAK disentuh (perilaku v0.22.3 tidak berubah).
        $this->assertDatabaseHas('referrers', ['id' => $referrer->id, 'user_id' => null]);
    }

    /**
     * v0.22.7 — kasus tanpa customer ter-link (mis. Sahrul) — tidak ada
     * efek apa pun ke tabel customers, tidak ada exception.
     */
    public function test_delete_of_a_staff_referrer_with_no_customers_linked_has_no_effect_on_customers(): void
    {
        $tenant = Tenant::factory()->create();
        $result = (new StaffService)->create([
            'name' => 'Staff Referrer Tanpa Customer',
            'phone' => '081234566662',
            'role' => 'teknisi',
            'tenant_id' => $tenant->id,
        ], ['type' => ReferrerType::Teknisi->value]);

        $unrelatedCustomer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'referred_by_referrer_id' => null,
        ]);

        (new StaffService)->delete($result['user']);

        $unrelatedCustomer->refresh();
        $this->assertFalse($unrelatedCustomer->referral_locked);
        $this->assertNull($unrelatedCustomer->locked_former_referrer_name);
    }

    public function test_linked_referrer_returns_the_correct_referrer_for_a_linked_staff_and_null_otherwise(): void
    {
        $tenant = Tenant::factory()->create();
        $linked = (new StaffService)->create([
            'name' => 'Staff Linked',
            'phone' => '081234588888',
            'role' => 'teknisi',
            'tenant_id' => $tenant->id,
        ], ['type' => ReferrerType::Teknisi->value]);
        $notLinked = (new StaffService)->create([
            'name' => 'Staff Not Linked',
            'phone' => '081234599999',
            'role' => 'noc',
            'tenant_id' => $tenant->id,
        ]);

        $service = new StaffService;

        $this->assertSame($linked['referrer']->id, $service->linkedReferrer($linked['user'])->id);
        $this->assertNull($service->linkedReferrer($notLinked['user']));
    }

    // ═══════════════════════════════════════════════════════════════
    // v0.22.4 — auto-kirim password awal (2 pesan WA terpisah)
    // ═══════════════════════════════════════════════════════════════

    public function test_create_queues_two_separate_whatsapp_messages_for_the_initial_password(): void
    {
        $tenant = Tenant::factory()->create();
        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::StaffInitialPasswordNotice,
            'content' => 'Halo {recipient_name}, password login dikirim di pesan berikutnya — {company_name}.',
            'is_active' => true,
        ]);

        $result = (new StaffService)->create([
            'name' => 'Staff Password Test',
            'phone' => '081234800000',
            'role' => 'noc',
            'tenant_id' => $tenant->id,
        ]);

        $normalizedPhone = WhatsappPhone::normalize('081234800000');

        // Pesan 1 — teks pengantar, lewat template.
        $this->assertDatabaseHas('whatsapp_message_logs', [
            'phone_number' => $normalizedPhone,
            'event_type' => WhatsappEventType::StaffInitialPasswordNotice->value,
        ]);

        // Pesan 2 — password POLOS, template_id null, rendered_content
        // PERSIS sama dengan password yang di-generate (tidak ada
        // karakter/format lain menempel).
        $this->assertDatabaseHas('whatsapp_message_logs', [
            'phone_number' => $normalizedPhone,
            'event_type' => WhatsappEventType::StaffInitialPasswordValue->value,
            'template_id' => null,
            'rendered_content' => $result['generated_password'],
        ]);

        $this->assertSame(2, WhatsappMessageLog::where('phone_number', $normalizedPhone)->count());
    }

    /**
     * Template `StaffInitialPasswordNotice` BELUM di-seed (skenario nyata
     * kalau `WhatsappMessageTemplateSeeder` belum sempat dijalankan ulang
     * untuk tenant ini) — pesan 1 gagal ter-queue (buildAndQueueForRecipient()
     * return null, cuma log warning), TAPI staff TETAP berhasil dibuat DAN
     * pesan 2 (password polos, tidak butuh template sama sekali) TETAP
     * ter-queue seperti biasa. Non-fatal sepenuhnya — tidak ada exception
     * yang bocor ke caller.
     */
    public function test_create_still_succeeds_and_queues_the_password_message_when_the_notice_template_is_missing(): void
    {
        $tenant = Tenant::factory()->create();
        // SENGAJA tidak seed template StaffInitialPasswordNotice.

        $result = (new StaffService)->create([
            'name' => 'Staff Tanpa Template Notice',
            'phone' => '081234811111',
            'role' => 'noc',
            'tenant_id' => $tenant->id,
        ]);

        $this->assertDatabaseHas('users', ['id' => $result['user']->id]);
        $this->assertNotEmpty($result['generated_password']);

        $this->assertDatabaseMissing('whatsapp_message_logs', [
            'event_type' => WhatsappEventType::StaffInitialPasswordNotice->value,
        ]);
        $this->assertDatabaseHas('whatsapp_message_logs', [
            'phone_number' => WhatsappPhone::normalize('081234811111'),
            'event_type' => WhatsappEventType::StaffInitialPasswordValue->value,
            'rendered_content' => $result['generated_password'],
        ]);
    }

    /**
     * `StaffInitialPasswordValue` TIDAK PERNAH melalui
     * `WhatsappTemplateService::resolve()` — dibuktikan langsung dengan
     * menghapus SEMUA WhatsappMessageTemplate (termasuk yang mungkin
     * sudah ada untuk event lain), pesan password tetap ter-queue persis.
     */
    public function test_the_password_value_message_never_goes_through_the_template_system(): void
    {
        $tenant = Tenant::factory()->create();
        $this->assertSame(0, WhatsappMessageTemplate::withoutGlobalScopes()->count());

        $result = (new StaffService)->create([
            'name' => 'Staff Nol Template',
            'phone' => '081234822222',
            'role' => 'noc',
            'tenant_id' => $tenant->id,
        ]);

        $log = WhatsappMessageLog::where('event_type', WhatsappEventType::StaffInitialPasswordValue->value)->first();

        $this->assertNotNull($log);
        $this->assertNull($log->template_id);
        $this->assertSame($result['generated_password'], $log->rendered_content);
    }
}
