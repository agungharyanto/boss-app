<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\CpeDeviceIndex;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.7.6-follow-up: the list itself, its actions, and its detail view no
 * longer go through Livewire at all (see App\Http\Controllers\Api\Internal\
 * CpeDeviceDatatableController / CpeDeviceActionController /
 * CpeDeviceDetailController + Tests\Feature\Network\
 * CpeDeviceDatatableControllerTest / CpeDeviceActionControllerTest /
 * CpeDeviceDetailControllerTest for that coverage now). All that's left on
 * CpeDeviceIndex itself is the authorization gate on mount() and rendering
 * the static page shell (poll-interval dropdown + empty DataTables table).
 */
class CpeDeviceIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_non_admin_non_reseller_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(CpeDeviceIndex::class)
            ->assertForbidden();
    }

    public function test_admin_can_render_the_page_shell(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceIndex::class)
            ->assertOk()
            ->assertSee('Perangkat CPE')
            ->assertSee('Auto-reload');
    }

    public function test_poll_interval_dropdown_has_the_expected_options(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceIndex::class)
            ->assertSee('5 detik')
            ->assertSee('10 detik')
            ->assertSee('30 detik')
            ->assertSee('60 detik')
            ->assertSee('5 menit');
    }
}
