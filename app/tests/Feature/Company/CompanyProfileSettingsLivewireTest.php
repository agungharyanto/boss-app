<?php

namespace Tests\Feature\Company;

use App\Livewire\Settings\CompanyProfileSettings;
use App\Models\CompanyProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyProfileSettingsLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['invoice.company_name' => '', 'invoice.company_phone' => '', 'invoice.company_address' => '']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        return $admin;
    }

    public function test_non_admin_cannot_mount(): void
    {
        $user = User::factory()->create();
        $user->assignRole('billing');

        Livewire::actingAs($user)->test(CompanyProfileSettings::class)->assertForbidden();
    }

    public function test_saving_persists_fields_and_records_updated_by(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CompanyProfileSettings::class)
            ->set('legalName', 'PT Jaringan Nusantara')
            ->set('brandName', 'NusaNet')
            ->set('csWhatsapp', '0812-3456-7890')
            ->set('address', 'Jl. Contoh No. 1')
            ->call('save')
            ->assertHasNoErrors();

        $profile = CompanyProfile::where('tenant_id', $admin->tenant_id)->first();
        $this->assertSame('PT Jaringan Nusantara', $profile->legal_name);
        $this->assertSame('NusaNet', $profile->brand_name);
        $this->assertSame($admin->id, $profile->updated_by);
    }

    public function test_missing_banner_reflects_unset_critical_fields(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CompanyProfileSettings::class)
            ->assertViewHas('missing', fn ($missing) => in_array('Nomor Customer Service', $missing, true));
    }

    public function test_png_logo_is_accepted_and_stored_on_private_disk(): void
    {
        Storage::fake('local');
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CompanyProfileSettings::class)
            ->set('logo', UploadedFile::fake()->create('logo.png', 100, 'image/png'))
            ->call('save')
            ->assertHasNoErrors();

        $profile = CompanyProfile::where('tenant_id', $admin->tenant_id)->first();
        $this->assertNotNull($profile->logo_path);
        $this->assertStringEndsWith('.png', $profile->logo_path);
        Storage::disk('local')->assertExists($profile->logo_path);
    }

    public function test_svg_logo_is_rejected(): void
    {
        Storage::fake('local');
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CompanyProfileSettings::class)
            ->set('logo', UploadedFile::fake()->create('evil.svg', 100, 'image/svg+xml'))
            ->call('save')
            ->assertHasErrors(['logo']);
    }

    public function test_fake_extension_non_image_logo_is_rejected(): void
    {
        Storage::fake('local');
        $admin = $this->admin();

        // Nama .png tapi isi/MIME teks → mimes menolak (ekstensi palsu).
        Livewire::actingAs($admin)
            ->test(CompanyProfileSettings::class)
            ->set('logo', UploadedFile::fake()->create('logo.png', 100, 'text/plain'))
            ->call('save')
            ->assertHasErrors(['logo']);
    }

    public function test_oversize_logo_is_rejected(): void
    {
        Storage::fake('local');
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CompanyProfileSettings::class)
            ->set('logo', UploadedFile::fake()->create('big.png', 5000, 'image/png'))
            ->call('save')
            ->assertHasErrors(['logo']);
    }
}
