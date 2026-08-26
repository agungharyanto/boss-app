<?php

namespace Tests\Feature\Auth;

use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.9.2 fix — root `/` used to be Laravel's own scaffold "welcome" view,
 * never replaced since v0.1.0. Now branches by auth state + admin-panel
 * eligibility (see EnsureAdminPanelAccess::userHasAccess()).
 */
class RootRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_guest_visiting_root_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_admin_eligible_user_visiting_root_is_redirected_to_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('superadmin');

        $this->actingAs($user)->get('/')->assertRedirect(route('web.dashboard'));
    }

    public function test_pure_referrer_visiting_root_is_redirected_to_the_portal_not_the_admin_dashboard(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Referrer::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect(route('web.referrer-portal.dashboard'));
        // Prove the redirect target itself doesn't immediately 403 —
        // the whole point of branching root by admin-panel eligibility.
        $this->followRedirects($response)->assertOk();
    }
}
