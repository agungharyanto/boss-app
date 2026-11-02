<?php

namespace Tests\Feature\Company;

use App\Livewire\Resellers\ResellerShow;
use App\Models\Reseller;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ResellerCsOverrideLivewireTest extends TestCase
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

    public function test_admin_can_save_reseller_cs_override_via_existing_reseller_ui(): void
    {
        $admin = $this->admin();
        $reseller = Reseller::create([
            'tenant_id' => $admin->tenant_id,
            'name' => 'Reseller A',
            'slug' => 'reseller-a',
        ]);

        Livewire::actingAs($admin)
            ->test(ResellerShow::class, ['reseller' => $reseller])
            ->set('csWhatsapp', '0899-OVERRIDE')
            ->set('csPhone', '021-555')
            ->call('saveCsOverride')
            ->assertHasNoErrors();

        $reseller->refresh();
        $this->assertSame('0899-OVERRIDE', $reseller->cs_whatsapp);
        $this->assertSame('021-555', $reseller->cs_phone);
    }

    public function test_mount_prefills_existing_cs_override(): void
    {
        $admin = $this->admin();
        $reseller = Reseller::create([
            'tenant_id' => $admin->tenant_id,
            'name' => 'Reseller B',
            'slug' => 'reseller-b',
            'cs_whatsapp' => '0812-EXISTING',
        ]);

        Livewire::actingAs($admin)
            ->test(ResellerShow::class, ['reseller' => $reseller])
            ->assertSet('csWhatsapp', '0812-EXISTING');
    }
}
