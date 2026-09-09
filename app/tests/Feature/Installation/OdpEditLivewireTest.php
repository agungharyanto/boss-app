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

    public function test_form_renders_with_editable_code_name_and_topology_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ODP-999', 'name' => 'ODP Test']);

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpEdit::class, ['odp' => $odp])
            ->assertOk()
            ->assertSet('code', 'ODP-999')
            ->assertSet('name', 'ODP Test')
            ->assertSeeHtml('wire:model="code"')
            ->assertSeeHtml('wire:model="name"');
    }

    public function test_revisi3_a_edit_code_and_name_persists(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ODP-OLD', 'name' => 'Nama Lama']);

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpEdit::class, ['odp' => $odp])
            ->set('code', 'ODP-BARU')
            ->set('name', 'Depan Masjid Al-Falah')
            ->set('lossInDb', '0.5')
            ->set('lossOutDb', '0.5')
            ->call('save')
            ->assertHasNoErrors();

        $odp->refresh();
        $this->assertSame('ODP-BARU', $odp->code);
        $this->assertSame('Depan Masjid Al-Falah', $odp->name);
    }

    public function test_revisi3_a_code_must_stay_unique_within_the_tenant_but_own_row_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        Odp::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ODP-TAKEN']);
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ODP-MINE']);

        // collide with another row -> rejected
        Livewire::actingAs($this->admin($tenant))
            ->test(OdpEdit::class, ['odp' => $odp])
            ->set('code', 'ODP-TAKEN')
            ->set('lossInDb', '0.5')
            ->set('lossOutDb', '0.5')
            ->call('save')
            ->assertHasErrors('code');

        $this->assertSame('ODP-MINE', $odp->fresh()->code);

        // re-saving with the SAME (own) code is fine
        Livewire::actingAs($this->admin($tenant))
            ->test(OdpEdit::class, ['odp' => $odp->fresh()])
            ->set('lossInDb', '0.5')
            ->set('lossOutDb', '0.5')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_revisi3_a_code_and_name_are_required(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpEdit::class, ['odp' => $odp])
            ->set('code', '')
            ->set('name', '')
            ->set('lossInDb', '0.5')
            ->set('lossOutDb', '0.5')
            ->call('save')
            ->assertHasErrors(['code', 'name']);
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

    public function test_saving_leaves_total_ports_untouched(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ODP-KEEP', 'name' => 'Nama Asli', 'total_ports' => 8]);

        Livewire::actingAs($this->admin($tenant))
            ->test(OdpEdit::class, ['odp' => $odp])
            ->set('lossInDb', '0.5')
            ->set('lossOutDb', '0.5')
            ->call('save');

        $odp->refresh();
        // code/name unchanged because the form was pre-filled with them
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
