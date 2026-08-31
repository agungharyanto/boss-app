<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\CapacityReport;
use App\Models\FiberAccessory;
use App\Models\FiberNode;
use App\Models\Odp;
use App\Models\Splitter;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\FiberTopologyService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CapacityReportLivewireTest extends TestCase
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

    public function test_odp_capacity_matches_used_vs_total_ports_fixture(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ODP-CAP', 'name' => 'Cap Test', 'total_ports' => 4]);
        $odp->provisionPorts();
        $odp->ports()->take(3)->get()->each->update(['status' => 'used']);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(CapacityReport::class)
            ->assertOk()
            ->html();

        $this->assertStringContainsString('ODP-CAP - Cap Test', $html);
        $this->assertStringContainsString('3 / 4', $html);
        $this->assertStringContainsString('75%', $html);
    }

    public function test_splitter_capacity_matches_accessory_count_vs_ratio_output_fixture(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $splitter = Splitter::factory()->create(['owner_type' => FiberNode::class, 'owner_id' => $node->id, 'ratio' => '1:8']);
        FiberAccessory::factory()->count(2)->create(['fiber_cable_id' => null, 'splitter_id' => $splitter->id]);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(CapacityReport::class)
            ->assertOk()
            ->html();

        $this->assertStringContainsString('Splitter 1:8', $html);
        $this->assertStringContainsString('2 / 8', $html);
        $this->assertStringContainsString('25%', $html);
    }

    public function test_cable_capacity_matches_used_vs_total_cores_fixture(): void
    {
        $tenant = Tenant::factory()->create();
        $from = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $to = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $cable = app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $from->id,
            'to_type' => FiberNode::class, 'to_id' => $to->id,
            'total_cores' => 4, 'tube_count' => 1, 'cores_per_tube' => 4,
        ]);
        $cable->cores()->limit(1)->get()->each->update(['status' => 'used']);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(CapacityReport::class)
            ->assertOk()
            ->html();

        $this->assertStringContainsString("Kabel #{$cable->id}", $html);
        $this->assertStringContainsString('1 / 4', $html);
        $this->assertStringContainsString('25%', $html);
    }

    public function test_search_filters_by_label(): void
    {
        $tenant = Tenant::factory()->create();
        Odp::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ODP-FINDME', 'name' => 'Findable']);
        Odp::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ODP-OTHER', 'name' => 'Other One']);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(CapacityReport::class)
            ->set('search', 'findme')
            ->html();

        $this->assertStringContainsString('ODP-FINDME', $html);
        $this->assertStringNotContainsString('ODP-OTHER', $html);
    }

    public function test_only_near_full_filter_hides_low_usage_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $full = Odp::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ODP-FULL', 'name' => 'Almost Full', 'total_ports' => 10]);
        $full->provisionPorts();
        $full->ports()->take(9)->get()->each->update(['status' => 'used']);

        $empty = Odp::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ODP-EMPTY', 'name' => 'Mostly Empty', 'total_ports' => 10]);
        $empty->provisionPorts();

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(CapacityReport::class)
            ->set('onlyNearFull', true)
            ->html();

        $this->assertStringContainsString('ODP-FULL', $html);
        $this->assertStringNotContainsString('ODP-EMPTY', $html);
    }

    public function test_non_admin_tier_user_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(CapacityReport::class)
            ->assertForbidden();
    }
}
