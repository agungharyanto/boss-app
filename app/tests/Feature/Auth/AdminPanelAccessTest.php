<?php

namespace Tests\Feature\Auth;

use App\Enums\ResellerUserRole;
use App\Enums\ResellerUserStatus;
use App\Models\Reseller;
use App\Models\ResellerUser;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.9.2 — EnsureAdminPanelAccess (admin.panel middleware). Covers the exact
 * regression this middleware could introduce if scoped too narrowly (see
 * its own docblock for the two real regressions caught building it).
 */
class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_superadmin_can_access_the_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('superadmin');

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_administrator_can_access_the_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('administrator');

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    /**
     * @dataProvider staffRoleProvider
     */
    public function test_every_other_existing_staff_role_still_reaches_the_dashboard(string $role): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function staffRoleProvider(): array
    {
        return [
            'noc' => ['noc'],
            'customer_service' => ['customer_service'],
            'teknisi' => ['teknisi'],
            'billing' => ['billing'],
            'sales_internal' => ['sales_internal'],
            'sales_freelance' => ['sales_freelance'],
            'finance' => ['finance'],
        ];
    }

    public function test_a_reseller_owner_with_zero_spatie_permissions_still_reaches_the_dashboard(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        ResellerUser::create([
            'reseller_id' => $reseller->id,
            'user_id' => $user->id,
            'role' => ResellerUserRole::Owner,
            'status' => ResellerUserStatus::Active,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_a_user_with_no_role_no_permission_and_no_reseller_membership_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_not_403(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
