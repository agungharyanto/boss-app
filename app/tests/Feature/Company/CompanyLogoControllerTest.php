<?php

namespace Tests\Feature\Company;

use App\Models\CompanyProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyLogoControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        return $admin;
    }

    public function test_user_without_company_profile_permission_is_forbidden(): void
    {
        // billing lolos admin.panel (punya invoices.*) tapi TIDAK punya
        // company_profile.view → controller menolak 403.
        $user = User::factory()->create();
        $user->assignRole('billing');

        $this->actingAs($user)->get(route('web.settings.company-logo'))->assertForbidden();
    }

    public function test_returns_404_when_no_logo_uploaded(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('web.settings.company-logo'))->assertNotFound();
    }

    public function test_streams_logo_with_nosniff_and_correct_content_type(): void
    {
        Storage::fake('local');
        $admin = $this->admin();

        $path = 'company-logos/'.$admin->tenant_id.'.png';
        Storage::disk('local')->put($path, 'fake-png-bytes');
        CompanyProfile::create(['tenant_id' => $admin->tenant_id, 'logo_path' => $path]);

        $this->actingAs($admin)
            ->get(route('web.settings.company-logo'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'image/png');
    }
}
