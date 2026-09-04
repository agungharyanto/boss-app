<?php

namespace Tests\Feature\Auth;

use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v0.9.2 — Referrer portal login (phone + password, separate from Fortify's
 * email-based /login), and the referrer.portal middleware boundary.
 */
class ReferrerPortalLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function referrerWithLoginAccount(string $phone = '081234567890', string $password = 'rahasia123', bool $isActive = true): Referrer
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make($password),
        ]);

        return Referrer::factory()->create([
            'tenant_id' => $tenant->id,
            'phone' => $phone,
            'user_id' => $user->id,
            'is_active' => $isActive,
        ]);
    }

    public function test_login_succeeds_with_correct_phone_and_password(): void
    {
        $referrer = $this->referrerWithLoginAccount();

        $response = $this->post('/referrer/login', [
            'phone' => $referrer->phone,
            'password' => 'rahasia123',
        ]);

        // v0.22.x login terpadu — jalur compat redirect ke `/`, yang lalu
        // meneruskan Referrer murni ke portalnya.
        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($referrer->user);
        $this->followRedirects($response)->assertOk();
    }

    public function test_login_fails_with_unregistered_phone(): void
    {
        $this->referrerWithLoginAccount();

        $response = $this->post('/referrer/login', [
            'phone' => '089999999999',
            'password' => 'rahasia123',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $referrer = $this->referrerWithLoginAccount();

        $response = $this->post('/referrer/login', [
            'phone' => $referrer->phone,
            'password' => 'password-salah',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_login_fails_for_an_inactive_referrer(): void
    {
        $referrer = $this->referrerWithLoginAccount(isActive: false);

        $response = $this->post('/referrer/login', [
            'phone' => $referrer->phone,
            'password' => 'rahasia123',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_login_fails_for_a_referrer_with_no_login_account_yet(): void
    {
        $tenant = Tenant::factory()->create();
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id, 'phone' => '081234500000', 'user_id' => null]);

        $response = $this->post('/referrer/login', [
            'phone' => $referrer->phone,
            'password' => 'anything',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_logged_in_referrer_can_reach_the_portal_dashboard(): void
    {
        $referrer = $this->referrerWithLoginAccount();

        $this->actingAs($referrer->user)->get('/referrer-portal')->assertOk();
    }

    public function test_logged_in_referrer_cannot_reach_admin_routes(): void
    {
        $referrer = $this->referrerWithLoginAccount();

        $this->actingAs($referrer->user)->get('/dashboard')->assertForbidden();
    }

    public function test_an_admin_user_with_no_linked_referrer_cannot_reach_the_referrer_portal(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        $this->actingAs($user)->get('/referrer-portal')->assertForbidden();
    }

    public function test_already_authenticated_user_visiting_the_referrer_login_page_is_redirected_away(): void
    {
        $referrer = $this->referrerWithLoginAccount();

        $this->actingAs($referrer->user)->get('/referrer/login')->assertRedirect();
    }

    public function test_guest_visiting_the_old_referrer_login_url_is_redirected_to_the_unified_login(): void
    {
        // v0.22.x — `/referrer/login` dipertahankan cuma untuk kompatibilitas
        // link lama; GET-nya redirect ke pintu terpadu `/login`.
        $this->get('/referrer/login')->assertRedirect(route('login'));
    }

    public function test_referrer_logout_ends_the_session_and_redirects_to_the_unified_login(): void
    {
        $referrer = $this->referrerWithLoginAccount();

        $response = $this->actingAs($referrer->user)->post('/referrer/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_logout_via_fortifys_shared_route_still_ends_up_at_login_via_root(): void
    {
        $user = User::factory()->create();
        $user->assignRole('superadmin');

        // Fortify's own LogoutResponse redirects to '/' (no override
        // registered) — root then redirects a guest to /login, so the
        // effective end state is the same even though it's two hops.
        $this->actingAs($user)->post('/logout')->assertRedirect('/');
        $this->assertGuest();

        $this->get('/')->assertRedirect(route('login'));
    }
}
