<?php

namespace Tests\Feature\Company;

use App\Livewire\Settings\InstallationTermsSettings;
use App\Models\InstallationTerms;
use App\Models\User;
use App\Services\Company\InstallationTermsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InstallationTermsSettingsLivewireTest extends TestCase
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

    public function test_non_admin_cannot_mount(): void
    {
        $user = User::factory()->create();
        $user->assignRole('billing');

        Livewire::actingAs($user)->test(InstallationTermsSettings::class)->assertForbidden();
    }

    public function test_saving_creates_a_new_version(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(InstallationTermsSettings::class)
            ->set('body', 'Teks dengan {nama_perusahaan} dan {nomor_cs}.')
            ->call('save')
            ->assertHasNoErrors();

        $active = app(InstallationTermsService::class)->active($admin->tenant_id);
        $this->assertSame(1, $active->version);
        $this->assertSame($admin->id, $active->created_by);

        Livewire::actingAs($admin)
            ->test(InstallationTermsSettings::class)
            ->set('body', 'Teks versi dua {nama_perusahaan}.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, app(InstallationTermsService::class)->active($admin->tenant_id)->version);
        $this->assertSame(2, InstallationTerms::where('tenant_id', $admin->tenant_id)->count());
    }

    public function test_unknown_variable_is_rejected_on_save(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(InstallationTermsSettings::class)
            ->set('body', 'Teks {variabel_aneh} tidak dikenal.')
            ->call('save')
            ->assertHasErrors(['body']);

        $this->assertSame(0, InstallationTerms::where('tenant_id', $admin->tenant_id)->count());
    }

    public function test_empty_body_is_rejected(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(InstallationTermsSettings::class)
            ->set('body', '   ')
            ->call('save')
            ->assertHasErrors(['body']);
    }

    public function test_insert_variable_appends_whitelisted_token(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(InstallationTermsSettings::class)
            ->set('body', 'Awal.')
            ->call('insertVariable', 'nomor_cs')
            ->assertSet('body', 'Awal.{nomor_cs}')
            ->call('insertVariable', 'variabel_jahat') // non-whitelist → diabaikan
            ->assertSet('body', 'Awal.{nomor_cs}');
    }

    public function test_preview_renders_sample_data(): void
    {
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)
            ->test(InstallationTermsSettings::class)
            ->set('body', 'CS: {nomor_cs}')
            ->call('previewTerms');

        $this->assertStringContainsString('CS: 0812-3456-7890', $component->get('preview'));
    }

    public function test_use_version_loads_old_body_as_draft_and_saving_makes_a_new_version(): void
    {
        $admin = $this->admin();
        $service = app(InstallationTermsService::class);
        $v1 = $service->saveNewVersion($admin->tenant_id, 'Teks lama {nomor_cs}', $admin->id);
        $service->saveNewVersion($admin->tenant_id, 'Teks baru {nama_perusahaan}', $admin->id);

        $component = Livewire::actingAs($admin)
            ->test(InstallationTermsSettings::class)
            ->call('useVersion', $v1->id)
            ->assertSet('body', 'Teks lama {nomor_cs}');

        $component->call('save')->assertHasNoErrors();

        // Versi lama tetap utuh; simpan ulang = versi 3.
        $this->assertSame('Teks lama {nomor_cs}', $v1->fresh()->body);
        $this->assertSame(3, $service->active($admin->tenant_id)->version);
    }
}
