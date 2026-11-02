<?php

namespace Tests\Feature\Company;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyProfileSidebarTest extends TestCase
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

    public function test_admin_tier_sees_both_new_settings_links(): void
    {
        $admin = $this->userWithRole('administrator');

        $this->actingAs($admin)
            ->get('/settings/theme')
            ->assertSee('Profil Perusahaan')
            ->assertSee('Syarat &amp; Ketentuan Pemasangan', false);
    }

    public function test_non_admin_tier_does_not_see_the_new_settings_links(): void
    {
        // billing lolos admin.panel tapi tidak punya company_profile.view.
        $billing = $this->userWithRole('billing');

        $this->actingAs($billing)
            ->get('/settings/theme')
            ->assertDontSee('Profil Perusahaan')
            ->assertDontSee('Syarat &amp; Ketentuan Pemasangan', false);
    }
}
