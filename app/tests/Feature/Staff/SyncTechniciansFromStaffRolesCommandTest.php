<?php

namespace Tests\Feature\Staff;

use App\Enums\TechnicianStatus;
use App\Models\Technician;
use App\Models\Tenant;
use App\Services\StaffService;
use App\Support\WhatsappPhone;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * `technicians:sync-from-staff-roles` — backfill sekali-jalan, dry-run BY
 * DEFAULT. Reuse StaffService::syncTechnicianStatus() (sudah dites penuh di
 * StaffServiceTest) — test di sini fokus ke perilaku COMMAND-nya sendiri
 * (dry-run vs --apply, idempotensi, laporan mismatch), bukan mengulang
 * skenario sinkronisasi yang sudah dites di level service.
 */
class SyncTechniciansFromStaffRolesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Bus::fake();
    }

    public function test_dry_run_by_default_reports_but_writes_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $user = (new StaffService)->create([
            'name' => 'Teknisi Dryrun',
            'phone' => '081244000001',
            'role' => 'teknisi',
            'tenant_id' => $tenant->id,
        ])['user'];
        // Hapus baris yang otomatis tercipta StaffService::create() sendiri
        // supaya command ini benar-benar diuji dari kondisi "belum
        // tersinkron" (skenario nyata backfill untuk staff yang sudah ada
        // SEBELUM auto-sync ini dibangun).
        Technician::withoutGlobalScopes()->where('user_id', $user->id)->delete();
        $this->assertSame(0, Technician::withoutGlobalScopes()->count());

        $this->artisan('technicians:sync-from-staff-roles')
            ->expectsOutputToContain('MODE DRY-RUN')
            ->expectsOutputToContain('[BARU]')
            ->assertExitCode(0);

        // NOL tulis ke DB — mode dry-run murni laporan.
        $this->assertSame(0, Technician::withoutGlobalScopes()->count());
    }

    public function test_apply_flag_actually_creates_the_missing_row(): void
    {
        $tenant = Tenant::factory()->create();
        $user = (new StaffService)->create([
            'name' => 'Teknisi Apply',
            'phone' => '081244000002',
            'role' => 'teknisi',
            'tenant_id' => $tenant->id,
        ])['user'];
        Technician::withoutGlobalScopes()->where('user_id', $user->id)->delete();
        $this->assertSame(0, Technician::withoutGlobalScopes()->count());

        $this->artisan('technicians:sync-from-staff-roles', ['--apply' => true])
            ->expectsOutputToContain('MODE APPLY')
            ->assertExitCode(0);

        $this->assertDatabaseHas('technicians', [
            'user_id' => $user->id,
            'name' => 'Teknisi Apply',
            'phone' => WhatsappPhone::normalize('081244000002'),
            'status' => TechnicianStatus::Active->value,
        ]);
    }

    public function test_apply_is_idempotent_running_twice_does_not_duplicate(): void
    {
        $tenant = Tenant::factory()->create();
        $user = (new StaffService)->create([
            'name' => 'Teknisi Idempoten',
            'phone' => '081244000003',
            'role' => 'teknisi',
            'tenant_id' => $tenant->id,
        ])['user'];
        Technician::withoutGlobalScopes()->where('user_id', $user->id)->delete();

        $this->artisan('technicians:sync-from-staff-roles', ['--apply' => true])->assertExitCode(0);
        $firstId = Technician::withoutGlobalScopes()->where('user_id', $user->id)->value('id');

        $this->artisan('technicians:sync-from-staff-roles', ['--apply' => true])
            ->expectsOutputToContain('[SUDAH SINKRON]')
            ->assertExitCode(0);

        $this->assertSame(1, Technician::withoutGlobalScopes()->where('user_id', $user->id)->count());
        $this->assertSame($firstId, Technician::withoutGlobalScopes()->where('user_id', $user->id)->value('id'));
    }

    /**
     * Baris Technician Active yang user-nya SUDAH TIDAK punya role teknisi
     * saat ini — dilaporkan, TIDAK diubah otomatis oleh command ini
     * (di luar scope backfill-dari-role, lihat docblock command).
     */
    public function test_reports_active_technician_rows_that_no_longer_match_a_teknisi_role(): void
    {
        $tenant = Tenant::factory()->create();
        $staff = (new StaffService)->create([
            'name' => 'Sudah Bukan Teknisi',
            'phone' => '081244000004',
            'role' => 'billing',
            'tenant_id' => $tenant->id,
        ])['user'];
        // Baris Technician Active yang, per definisi command ini, tidak
        // lagi cocok role user-nya saat ini — dibuat manual mensimulasikan
        // data lama sebelum auto-sync ini ada.
        $technician = Technician::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $staff->id,
            'status' => TechnicianStatus::Active,
        ]);

        $this->artisan('technicians:sync-from-staff-roles')
            ->expectsOutputToContain('TANPA role')
            ->assertExitCode(0);

        // Dry-run murni laporan — baris itu TIDAK diubah sama sekali.
        $this->assertDatabaseHas('technicians', [
            'id' => $technician->id,
            'status' => TechnicianStatus::Active->value,
        ]);
    }

    public function test_zero_technician_users_reports_cleanly_without_error(): void
    {
        Tenant::factory()->create();

        $this->artisan('technicians:sync-from-staff-roles')
            ->expectsOutputToContain("User dengan role 'teknisi': 0")
            ->assertExitCode(0);
    }
}
