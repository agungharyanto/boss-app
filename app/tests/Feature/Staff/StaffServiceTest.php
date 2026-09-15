<?php

namespace Tests\Feature\Staff;

use App\Enums\WorkOrderStatus;
use App\Models\CpeActionLog;
use App\Models\Reseller;
use App\Models\ResellerUser;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\StaffService;
use App\Support\WhatsappPhone;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
