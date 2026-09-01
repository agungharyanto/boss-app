<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\FiberCableForm;
use App\Models\FiberCable;
use App\Models\FiberCore;
use App\Models\FiberNode;
use App\Models\Odp;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FiberCableFormLivewireTest extends TestCase
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

    public function test_odd_total_cores_is_rejected_with_a_message_and_no_cable_is_created(): void
    {
        $tenant = Tenant::factory()->create();
        $source = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $target = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberCableForm::class, ['fiber_node' => $source])
            ->set('toKey', FiberNode::class.'#'.$target->id)
            ->set('totalCores', '7')
            ->set('tubeCount', '7')
            ->set('coresPerTube', '1')
            ->call('save')
            ->assertHasErrors('totalCores')
            ->assertSee('Jumlah core harus genap.');

        $this->assertDatabaseCount('fiber_cables', 0);
    }

    public function test_tube_times_core_mismatch_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $source = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $target = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberCableForm::class, ['fiber_node' => $source])
            ->set('toKey', FiberNode::class.'#'.$target->id)
            ->set('totalCores', '12')
            ->set('tubeCount', '2')
            ->set('coresPerTube', '4')
            ->call('save')
            ->assertHasErrors('coresPerTube');

        $this->assertDatabaseCount('fiber_cables', 0);
    }

    public function test_a_valid_cable_generates_the_right_number_of_cores_with_cycle_colours(): void
    {
        $tenant = Tenant::factory()->create();
        $source = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $target = Odp::factory()->create(['tenant_id' => $tenant->id]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(FiberCableForm::class, ['fiber_node' => $source])
            ->set('toKey', Odp::class.'#'.$target->id)
            ->set('totalCores', '12')
            ->set('tubeCount', '2')
            ->set('coresPerTube', '6')
            ->call('save')
            ->assertHasNoErrors();

        $cable = FiberCable::firstOrFail();
        $this->assertSame($source->id, $cable->from_id);
        $this->assertSame(Odp::class, $cable->to_type);
        $this->assertSame(12, FiberCore::where('fiber_cable_id', $cable->id)->count());

        // Tube 1 core 1 = Biru, tube 1 core 2 = Orange (TIA/EIA-598-C cycle).
        $first = FiberCore::where('fiber_cable_id', $cable->id)->where('tube_number', 1)->where('core_number_in_tube', 1)->firstOrFail();
        $second = FiberCore::where('fiber_cable_id', $cable->id)->where('tube_number', 1)->where('core_number_in_tube', 2)->firstOrFail();
        $this->assertSame('Biru', $first->core_color);
        $this->assertSame('Orange', $second->core_color);

        $component->assertSee('Core Ter-generate');
    }

    public function test_a_core_colour_can_be_overridden_after_generation(): void
    {
        $tenant = Tenant::factory()->create();
        $source = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $target = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(FiberCableForm::class, ['fiber_node' => $source])
            ->set('toKey', FiberNode::class.'#'.$target->id)
            ->set('totalCores', '4')
            ->set('tubeCount', '2')
            ->set('coresPerTube', '2')
            ->call('save')
            ->assertHasNoErrors();

        $core = FiberCore::where('fiber_cable_id', $component->get('createdCableId'))->firstOrFail();

        $component->set("coreEdits.{$core->id}.core", 'Ungu Custom')
            ->call('saveCoreColors')
            ->assertHasNoErrors()
            ->assertRedirect(route('web.fiber-nodes.detail', $source->id));

        $this->assertSame('Ungu Custom', $core->refresh()->core_color);
    }

    public function test_target_dropdown_excludes_points_already_a_child_of_this_source(): void
    {
        $tenant = Tenant::factory()->create();
        $source = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'local_label' => 'SRC']);
        $alreadyChild = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'local_label' => 'ALREADY-CHILD']);
        $free = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'local_label' => 'STILL-FREE']);

        FiberCable::factory()->create([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class,
            'from_id' => $source->id,
            'to_type' => FiberNode::class,
            'to_id' => $alreadyChild->id,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberCableForm::class, ['fiber_node' => $source])
            ->assertSee('STILL-FREE')
            ->assertDontSee('ALREADY-CHILD');
    }

    public function test_non_manage_user_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $source = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo('network_infrastructure.view');

        Livewire::actingAs($viewer)
            ->test(FiberCableForm::class, ['fiber_node' => $source])
            ->assertForbidden();
    }
}
