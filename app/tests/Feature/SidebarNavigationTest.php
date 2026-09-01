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
     * v0.14.3.1 — Bandwidth Profile/IP Pool Pelanggan/Grup Profil/Profil
     * Hotspot/Profil PPP grouped under one collapsible "Profil Paket".
     * restrukturisasi-sidebar — grup ini pindah dari cluster "Network" ke
     * "Billing & Finance", parent-nya jadi TOGGLE-MURNI (bukan link),
     * Bandwidth Profile jadi child pertama. Reorganisasi visual: tidak ada
     * route yang berubah, tiap halaman tetap di URL aslinya.
     */
    public function test_admin_tier_user_sees_the_grouped_profil_paket_menu(): void
    {
        $user = $this->userWithRole('superadmin');

        $response = $this->actingAs($user)->get('/invoices');

        $response->assertSee('Profil Paket');
        $response->assertSee('Bandwidth Profile');
        $response->assertSee('IP Pool Pelanggan');
        $response->assertSee('Grup Profil');
        $response->assertSee('Profil Hotspot');
        $response->assertSee('Profil PPP');
    }

    public function test_profil_paket_parent_is_a_pure_toggle_not_a_link(): void
    {
        $user = $this->userWithRole('superadmin');

        $html = $this->actingAs($user)->get('/invoices')->getContent();

        // Parent row is a <button> toggling the subgroup, label wrapped in
        // a <span> — NOT an <a href> to any page.
        $this->assertMatchesRegularExpression(
            '/<button[^>]*aria-controls="sidebar-subgroup-profil-paket"[^>]*>\s*<span>Profil Paket<\/span>/s',
            $html
        );
        // Bandwidth Profile is still reachable — now as a child link.
        $this->assertStringContainsString(route('web.bandwidth-profiles.index'), $html);
        // The old link-parent markup (label directly inside an <a class="flex-1 ...">)
        // must not wrap "Profil Paket" anymore.
        $this->assertDoesNotMatchRegularExpression(
            '/<a href="[^"]*bandwidth-profiles[^"]*"\s+class="flex-1[^>]*>\s*Profil Paket/s',
            $html
        );
    }

    public function test_profil_paket_children_still_link_to_their_original_routes(): void
    {
        $user = $this->userWithRole('superadmin');

        $response = $this->actingAs($user)->get('/invoices');

        $response->assertSee(route('web.bandwidth-profiles.index'), false);
        $response->assertSee(route('web.customer-ip-pools.index'), false);
        $response->assertSee(route('web.network-profile-groups.index'), false);
        $response->assertSee(route('web.hotspot-packages.index'), false);
        $response->assertSee(route('web.ppp-packages.index'), false);
    }

    public function test_profil_paket_sits_in_billing_finance_not_network(): void
    {
        $user = $this->userWithRole('superadmin');

        $html = $this->actingAs($user)->get('/invoices')->getContent();

        $billingPos = strpos($html, '<span>Billing &amp; Finance</span>');
        $networkPos = strpos($html, '<span>Network</span>');
        $profilPaketPos = strpos($html, '<span>Profil Paket</span>');

        $this->assertNotFalse($billingPos);
        $this->assertNotFalse($networkPos);
        $this->assertNotFalse($profilPaketPos);
        // "Profil Paket" is rendered inside the Billing & Finance cluster,
        // which comes before the Network cluster in the sidebar.
        $this->assertGreaterThan($billingPos, $profilPaketPos);
        $this->assertLessThan($networkPos, $profilPaketPos);
    }

    public function test_package_pricing_link_is_gone_from_the_sidebar(): void
    {
        $user = $this->userWithRole('superadmin');

        $response = $this->actingAs($user)->get('/customers');

        $response->assertDontSee('Package Pricing');
        $response->assertDontSee(route('web.reseller-package-pricing.index'), false);
    }

    public function test_sidebar_clusters_default_collapsed_except_the_active_one(): void
    {
        $user = $this->userWithRole('superadmin');

        $html = $this->actingAs($user)->get('/invoices')->getContent();

        // Active cluster (Billing & Finance — /invoices lives here) auto-opens.
        $this->assertStringContainsString(
            "x-data=\"{ open: true || localStorage.getItem('sidebar-cluster-billing-finance') === 'true' }\"",
            $html
        );
        // Every other cluster defaults closed.
        $this->assertStringContainsString(
            "x-data=\"{ open: false || localStorage.getItem('sidebar-cluster-network') === 'true' }\"",
            $html
        );
        $this->assertStringContainsString(
            "x-data=\"{ open: false || localStorage.getItem('sidebar-cluster-pelanggan') === 'true' }\"",
            $html
        );
    }

    public function test_profil_paket_subgroup_auto_opens_on_its_own_pages(): void
    {
        $user = $this->userWithRole('superadmin');

        $onPage = $this->actingAs($user)->get('/hotspot-packages')->getContent();
        $this->assertStringContainsString(
            "x-data=\"{ subOpen: true || localStorage.getItem('sidebar-subgroup-profil-paket') === 'true' }\"",
            $onPage
        );

        $offPage = $this->actingAs($user)->get('/invoices')->getContent();
        $this->assertStringContainsString(
            "x-data=\"{ subOpen: false || localStorage.getItem('sidebar-subgroup-profil-paket') === 'true' }\"",
            $offPage
        );
    }

    /**
     * v0.16.0 Langkah 8 — the fiber-topology module moved out of the
     * "Network" cluster into its own top-level "Topology Fiber" cluster,
     * and "Topologi Fiber" (the FiberNodeIndex link/page) was renamed
     * "Daftar Perangkat Passive". A new "Peta Topologi" link joins it.
     */
    public function test_admin_tier_user_sees_the_topology_fiber_cluster(): void
    {
        $user = $this->userWithRole('superadmin');

        $response = $this->actingAs($user)->get('/fiber-nodes');

        $response->assertSee('Topology Fiber');
        $response->assertSee('Daftar Perangkat Passive');
        $response->assertSee('Peta Topologi');
        $response->assertSee('Kapasitas Jaringan');
        $response->assertSee(route('web.fiber-topology-map.index'), false);
        // old label is gone everywhere it was rendered
        $response->assertDontSee('Topologi Fiber');
    }

    public function test_non_admin_tier_user_does_not_see_the_topology_fiber_links(): void
    {
        // The cluster header renders for every user (same as "Network"),
        // but its permission-gated links must not — mirrors the Profil
        // Paket test below.
        $user = $this->userWithRole('customer_service');

        $response = $this->actingAs($user)->get('/customers');

        $response->assertDontSee('Daftar Perangkat Passive');
        $response->assertDontSee('Peta Topologi');
        $response->assertDontSee(route('web.fiber-topology-map.index'), false);
    }

    public function test_non_admin_tier_user_does_not_see_the_profil_paket_menu(): void
    {
        $user = $this->userWithRole('customer_service');

        $response = $this->actingAs($user)->get('/customers');

        $response->assertDontSee('Profil Paket');
        $response->assertDontSee('IP Pool Pelanggan');
        $response->assertDontSee('Grup Profil');
        $response->assertDontSee('Profil Hotspot');
    }
}
