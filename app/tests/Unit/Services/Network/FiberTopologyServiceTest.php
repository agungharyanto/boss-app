<?php

namespace Tests\Unit\Services\Network;

use App\Enums\FiberNodeType;
use App\Models\FiberNode;
use App\Models\Odp;
use App\Models\Tenant;
use App\Services\Network\FiberTopologyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * DB-touching (creates real FiberCable/FiberCore/FiberNode rows) but no
 * HTTP layer involved — same "Unit" placement precedent as
 * Tests\Unit\Services\Installation\OdpLocatorServiceTest.
 */
class FiberTopologyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_cable_rejects_an_odd_total_cores(): void
    {
        $tenant = Tenant::factory()->create();
        $from = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $to = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Jumlah core harus genap.');

        app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class,
            'from_id' => $from->id,
            'to_type' => FiberNode::class,
            'to_id' => $to->id,
            'total_cores' => 5,
            'tube_count' => 1,
            'cores_per_tube' => 5,
        ]);
    }

    public function test_create_cable_rejects_when_tube_count_times_cores_per_tube_does_not_match_total_cores(): void
    {
        $tenant = Tenant::factory()->create();
        $from = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $to = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(InvalidArgumentException::class);

        app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class,
            'from_id' => $from->id,
            'to_type' => FiberNode::class,
            'to_id' => $to->id,
            'total_cores' => 12,
            'tube_count' => 2,
            'cores_per_tube' => 4, // 2*4=8, not 12
        ]);
    }

    public function test_create_cable_generates_exactly_total_cores_fiber_core_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $from = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $to = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $cable = app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class,
            'from_id' => $from->id,
            'to_type' => FiberNode::class,
            'to_id' => $to->id,
            'total_cores' => 12,
            'tube_count' => 2,
            'cores_per_tube' => 6,
        ]);

        $this->assertSame(12, $cable->cores()->count());
        $this->assertSame(6, $cable->cores()->where('tube_number', 1)->count());
        $this->assertSame(6, $cable->cores()->where('tube_number', 2)->count());
    }

    public function test_create_cable_assigns_colors_from_the_tia_eia_598_c_cycle(): void
    {
        $tenant = Tenant::factory()->create();
        $from = FiberNode::factory()->create(['tenant_id' => $tenant->id]);
        $to = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $cable = app(FiberTopologyService::class)->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class,
            'from_id' => $from->id,
            'to_type' => FiberNode::class,
            'to_id' => $to->id,
            'total_cores' => 24,
            'tube_count' => 2,
            'cores_per_tube' => 12,
        ]);

        $tube1Core1 = $cable->cores()->where(['tube_number' => 1, 'core_number_in_tube' => 1])->firstOrFail();
        $tube2Core1 = $cable->cores()->where(['tube_number' => 2, 'core_number_in_tube' => 1])->firstOrFail();
        $tube1Core12 = $cable->cores()->where(['tube_number' => 1, 'core_number_in_tube' => 12])->firstOrFail();

        $this->assertSame('Biru', $tube1Core1->tube_color);
        $this->assertSame('Orange', $tube2Core1->tube_color);
        $this->assertSame('Biru', $tube1Core1->core_color);
        $this->assertSame('Toska', $tube1Core12->core_color);
    }

    public function test_loss_is_required_for_a_fiber_node_of_type_odc(): void
    {
        $service = app(FiberTopologyService::class);
        $target = new FiberNode(['node_type' => FiberNodeType::Odc]);

        $this->assertTrue($service->isLossRequired($target));
    }

    public function test_loss_is_not_required_for_otb_or_closure(): void
    {
        $service = app(FiberTopologyService::class);

        $this->assertFalse($service->isLossRequired(new FiberNode(['node_type' => FiberNodeType::Otb])));
        $this->assertFalse($service->isLossRequired(new FiberNode(['node_type' => FiberNodeType::Closure])));
    }

    /**
     * The "ODP" half of "loss wajib untuk ODC/ODP" — exercised directly
     * against the Service with a real Odp instance, since (per
     * docs/ROADMAP.md's v0.16.0 Langkah 2 notes) no dedicated Odp
     * FormRequest is built this Langkah — StoreOdpRequest/UpdateOdpRequest
     * (v0.5.0's existing registration flow) are deliberately left
     * untouched, per the explicit "don't disturb existing flow"
     * instruction. A future splice-data-entry form (Langkah 3+) would call
     * this exact same predicate.
     */
    public function test_loss_is_always_required_for_an_odp_regardless_of_any_field(): void
    {
        $service = app(FiberTopologyService::class);

        $this->assertTrue($service->isLossRequired(new Odp));
    }
}
