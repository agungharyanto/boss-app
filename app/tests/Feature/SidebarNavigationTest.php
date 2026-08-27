<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_guest_does_not_see_the_sidebar(): void
    {
        $this->get('/login')->assertDontSee('Daftar Pelanggan');
    }

    public function test_customer_service_does_not_see_the_register_link(): void
    {
        $user = $this->userWithRole('customer_service');

        $this->actingAs($user)
            ->get('/customers')
            ->assertSee('Daftar Pelanggan')
            ->assertDontSee('Registrasi Pelanggan');
    }

    public function test_sales_internal_sees_the_register_link(): void
    {
        $user = $this->userWithRole('sales_internal');

        $this->actingAs($user)
            ->get('/customers')
            ->assertSee('Registrasi Pelanggan');
    }

    /**
     * v0.14.3.1 — Bandwidth Profile/IP Pool Pelanggan/Grup Profil were 3
     * flat "Network" cluster items, now grouped under one collapsible
     * "Profil Paket" parent (same 'children' pattern as NAS/Perangkat
     * CPE) — the parent row IS Bandwidth Profile's own real link
     * (relabeled), IP Pool Pelanggan/Grup Profil are its children. This is
     * a purely visual reorganization: no route changed, so every
     * underlying page must still be reachable at its original URL.
     */
    public function test_admin_tier_user_sees_the_grouped_profil_paket_menu(): void
    {
        $user = $this->userWithRole('superadmin');

        $response = $this->actingAs($user)->get('/bandwidth-profiles');

        $response->assertSee('Profil Paket');
        $response->assertSee('IP Pool Pelanggan');
        $response->assertSee('Grup Profil');
    }

    public function test_profil_paket_parent_link_points_to_the_bandwidth_profiles_page(): void
    {
        $user = $this->userWithRole('superadmin');

        $this->actingAs($user)
            ->get('/customer-ip-pools')
            ->assertSee(route('web.bandwidth-profiles.index'), false);
    }

    public function test_profil_paket_children_still_link_to_their_original_routes(): void
    {
        $user = $this->userWithRole('superadmin');

        $response = $this->actingAs($user)->get('/network-profile-groups');

        $response->assertSee(route('web.customer-ip-pools.index'), false);
        $response->assertSee(route('web.network-profile-groups.index'), false);
    }

    public function test_non_admin_tier_user_does_not_see_the_profil_paket_menu(): void
    {
        $user = $this->userWithRole('customer_service');

        $response = $this->actingAs($user)->get('/customers');

        $response->assertDontSee('Profil Paket');
        $response->assertDontSee('IP Pool Pelanggan');
        $response->assertDontSee('Grup Profil');
    }
}
