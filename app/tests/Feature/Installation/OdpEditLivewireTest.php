<?php

namespace Tests\Feature\Installation;

use App\Livewire\Installation\OdpEdit;
use App\Models\FiberNode;
use App\Models\Odp;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 3. Covers the
 * genuinely NEW Odp edit page — never touches StoreOdpRequest/
 * UpdateOdpRequest/OdpController, which have their own, untouched test
 * coverage from v0.5.0.
 */
class OdpEditLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    public function test_form_renders_with_odps_read_only_identity_and_editable_topology_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ODP-999', 'name' => 'ODP Test']);

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpEdit::class, ['odp' => $odp])
            ->assertOk()
            ->assertSee('ODP-999')
            ->assertSee('ODP Test');
    }

    public function test_saving_without_loss_values_is_rejected_loss_is_always_required_for_odp(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpEdit::class, ['odp' => $odp])
            ->call('save')
            ->assertHasErrors(['lossInDb', 'lossOutDb']);
    }

    public function test_saving_with_loss_values_and_a_parent_link_succeeds(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id]);
        $parent = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'odc']);

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpEdit::class, ['odp' => $odp])
            ->set('parentId', (string) $parent->id)
            ->set('lossInDb', '0.8')
            ->set('lossOutDb', '1.1')
            ->call('save')
            ->assertHasNoErrors();

        $odp->refresh();
        $this->assertSame($parent->id, $odp->parent_id);
        $this->assertSame(FiberNode::class, $odp->parent_type);
        $this->assertEquals(0.8, (float) $odp->loss_in_db);
        $this->assertEquals(1.1, (float) $odp->loss_out_db);
    }

    public function test_saving_with_a_splitter_ratio_persists_the_splitter(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpEdit::class, ['odp' => $odp])
            ->set('lossInDb', '0.5')
            ->set('lossOutDb', '0.5')
            ->set('splitterRatio', '1:16')
            ->set('splitterModel', 'PLC-16')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('splitters', [
            'owner_type' => Odp::class,
            'owner_id' => $odp->id,
            'ratio' => '1:16',
            'model' => 'PLC-16',
        ]);
    }

    public function test_saving_never_touches_odps_own_core_registration_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ODP-KEEP', 'name' => 'Nama Asli', 'total_ports' => 8]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpEdit::class, ['odp' => $odp])
            ->set('lossInDb', '0.5')
            ->set('lossOutDb', '0.5')
            ->call('save');

        $odp->refresh();
        $this->assertSame('ODP-KEEP', $odp->code);
        $this->assertSame('Nama Asli', $odp->name);
        $this->assertSame(8, $odp->total_ports);
    }

    public function test_a_successful_save_redirects_to_the_topology_list_a_failed_one_stays_put(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpEdit::class, ['odp' => $odp])
            ->set('lossInDb', '0.5')
            ->set('lossOutDb', '0.5')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('web.fiber-nodes.index'));

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpEdit::class, ['odp' => $odp])
            ->call('save')
            ->assertHasErrors(['lossInDb', 'lossOutDb'])
            ->assertNoRedirect();
    }
}
