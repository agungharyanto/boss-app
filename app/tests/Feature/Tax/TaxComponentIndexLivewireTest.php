<?php

namespace Tests\Feature\Tax;

use App\Livewire\Tax\TaxComponentIndex;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaxComponentIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_render_and_create_a_tax_component(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');

        Livewire::actingAs($admin)
            ->test(TaxComponentIndex::class)
            ->assertOk()
            ->set('code', 'PPN')
            ->set('name', 'PPN')
            ->set('type', 'percentage')
            ->set('rate', '11')
            ->set('effective_from', now()->startOfMonth()->toDateString())
            ->call('createComponent')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tax_components', ['code' => 'PPN', 'tenant_id' => $tenant->id]);
    }

    public function test_non_admin_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = User::factory()->create(['tenant_id' => $tenant->id]);
        $billing->assignRole('billing');

        Livewire::actingAs($billing)
            ->test(TaxComponentIndex::class)
            ->assertForbidden();
    }
}
