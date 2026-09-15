<?php

namespace Tests\Feature\Staff;

use App\Models\Tenant;
use App\Models\User;
use App\Services\StaffService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
            'role' => $role,
            'tenant_id' => $tenant->id,
        ]);

        $user = $result['user'];

        $this->assertTrue($user->hasRole($role));
        $this->assertCount(1, $user->roles);
        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertFalse($user->is_disabled);
        $this->assertNotEmpty($result['generated_password']);
        $this->assertTrue(Hash::check($result['generated_password'], $user->password));
    }

    public function test_create_never_persists_the_generated_password_in_plaintext_anywhere_else(): void
    {
        $tenant = Tenant::factory()->create();

        $result = (new StaffService)->create([
            'name' => 'Staff Test',
            'email' => 'plaintext-check@boss.local',
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
            'role' => 'customer_service',
            'tenant_id' => $tenant->id,
        ]);
        $user = $result['user'];
        $originalHash = $user->password;

        $updated = (new StaffService)->update($user, [
            'name' => 'After',
            'email' => 'after@boss.local',
            'role' => 'billing',
        ]);

        $this->assertSame('After', $updated->name);
        $this->assertSame('after@boss.local', $updated->email);
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
            'role' => 'finance',
            'tenant_id' => $tenant->id,
        ]);
        $user = $result['user'];

        $disabled = (new StaffService)->disable($user);
        $this->assertTrue($disabled->is_disabled);

        $enabled = (new StaffService)->enable($disabled);
        $this->assertFalse($enabled->is_disabled);
    }
}
