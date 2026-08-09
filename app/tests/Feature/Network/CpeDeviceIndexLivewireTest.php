<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\CpeDeviceIndex;
use App\Models\CpeDevice;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

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

    public function test_admin_can_render_and_list_existing_devices(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'serial_number' => 'SNVISIBLE001']);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceIndex::class)
            ->assertOk()
            ->assertSee('SNVISIBLE001');
    }

    public function test_search_filters_by_serial_number(): void
    {
        $tenant = Tenant::factory()->create();
        CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'serial_number' => 'SNMATCHME']);
        CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'serial_number' => 'SNOTHER999']);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceIndex::class)
            ->set('search', 'MATCHME')
            ->assertSee('SNMATCHME')
            ->assertDontSee('SNOTHER999');
    }
}
