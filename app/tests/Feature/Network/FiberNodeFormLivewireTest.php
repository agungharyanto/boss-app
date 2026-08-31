<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\FiberNodeForm;
use App\Models\FiberNode;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FiberNodeFormLivewireTest extends TestCase
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

    public function test_create_form_renders(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->assertOk()
            ->assertSee('Titik Topologi Fiber Baru');
    }

    public function test_edit_form_renders_with_existing_values(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'local_label' => 'OTB-Edit']);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class, ['fiber_node' => $node])
            ->assertOk()
            ->assertSet('localLabel', 'OTB-Edit')
            ->assertSee('Edit Titik Topologi Fiber');
    }

    public function test_creating_an_otb_node_succeeds_without_loss_values(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'otb')
            ->set('localLabel', 'OTB-New')
            ->set('latitude', '-6.2000')
            ->set('longitude', '106.8000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fiber_nodes', ['local_label' => 'OTB-New', 'tenant_id' => $tenant->id]);
    }

    public function test_creating_an_odc_node_without_loss_values_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'odc')
            ->set('localLabel', 'ODC-New')
            ->call('save');

        $component->assertHasErrors(['lossInDb', 'lossOutDb']);
        $this->assertDatabaseMissing('fiber_nodes', ['local_label' => 'ODC-New']);
    }

    public function test_creating_an_odc_node_with_loss_values_succeeds(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'odc')
            ->set('localLabel', 'ODC-New')
            ->set('lossInDb', '1.2')
            ->set('lossOutDb', '1.5')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fiber_nodes', ['local_label' => 'ODC-New', 'loss_in_db' => 1.2, 'loss_out_db' => 1.5]);
    }

    public function test_editing_an_existing_node_updates_it(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'local_label' => 'Before']);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class, ['fiber_node' => $node])
            ->set('localLabel', 'After')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fiber_nodes', ['id' => $node->id, 'local_label' => 'After']);
    }

    public function test_gps_photo_capture_widget_only_appears_in_edit_mode(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $createHtml = Livewire::actingAs($this->admin($tenant))->test(FiberNodeForm::class)->html();
        $editHtml = Livewire::actingAs($this->admin($tenant))->test(FiberNodeForm::class, ['fiber_node' => $node])->html();

        $this->assertStringNotContainsString('Ambil lokasi saya', $createHtml);
        $this->assertStringContainsString('Ambil lokasi saya', $editHtml);
    }
}
