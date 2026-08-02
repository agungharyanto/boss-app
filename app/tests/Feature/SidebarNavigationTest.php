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
}
