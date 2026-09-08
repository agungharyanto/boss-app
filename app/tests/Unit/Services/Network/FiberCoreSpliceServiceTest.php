<?php

namespace Tests\Unit\Services\Network;

use App\Models\FiberCable;
use App\Models\FiberCoreSplice;
use App\Models\FiberNode;
use App\Models\Odp;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\FiberCoreSpliceService;
use App\Services\Network\FiberTopologyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * v0.16.1 Bagian B — core-to-core through-splice at a passive node.
 * Same "Unit + real DB rows, no HTTP" placement as FiberTopologyServiceTest.
 */
class FiberCoreSpliceServiceTest extends TestCase
{
    use RefreshDatabase;

    private FiberCoreSpliceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FiberCoreSpliceService::class);
    }

    /**
     * A Closure with two cables landing on it: cableA runs OTB -> Closure
     * (Closure is `to`), cableB runs Closure -> ODP (Closure is `from`).
     *
     * @return array{0: FiberNode, 1: FiberCable, 2: FiberCable}
     */
    private function nodeWithTwoCables(): array
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));

        $closure = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'closure', 'port_count' => null]);
        $otb = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'port_count' => 8]);
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        $topo = app(FiberTopologyService::class);
        $cableA = $topo->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $otb->id,
            'to_type' => FiberNode::class, 'to_id' => $closure->id,
            'total_cores' => 4, 'tube_count' => 2, 'cores_per_tube' => 2,
        ]);
        $cableB = $topo->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $closure->id,
            'to_type' => Odp::class, 'to_id' => $odp->id,
            'total_cores' => 4, 'tube_count' => 2, 'cores_per_tube' => 2,
        ]);

        return [$closure, $cableA, $cableB];
    }

    public function test_creates_a_valid_splice_between_two_cables_landing_on_the_node(): void
    {
        [$closure, $cableA, $cableB] = $this->nodeWithTwoCables();
        $a = $cableA->cores()->first();
        $b = $cableB->cores()->first();

        $splice = $this->service->createSplice($closure, $a, $b, 0.15, 'splice lapangan');

        $this->assertDatabaseHas('fiber_core_splices', [
            'id' => $splice->id,
            'splice_node_type' => FiberNode::class,
            'splice_node_id' => $closure->id,
            'from_fiber_core_id' => $a->id,
            'to_fiber_core_id' => $b->id,
            'note' => 'splice lapangan',
        ]);
        $this->assertSame('0.15', (string) $splice->loss_db);
        $this->assertNotNull($splice->performed_by);
    }

    public function test_rejects_two_cores_from_the_same_cable(): void
    {
        [$closure, $cableA] = $this->nodeWithTwoCables();
        $cores = $cableA->cores()->orderBy('id')->get();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('kabel yang berbeda');
        $this->service->createSplice($closure, $cores[0], $cores[1]);
    }

    public function test_rejects_a_core_to_itself(): void
    {
        [$closure, $cableA] = $this->nodeWithTwoCables();
        $a = $cableA->cores()->first();

        $this->expectException(InvalidArgumentException::class);
        $this->service->createSplice($closure, $a, $a);
    }

    public function test_rejects_a_core_that_is_already_spliced_on_either_side(): void
    {
        [$closure, $cableA, $cableB] = $this->nodeWithTwoCables();
        $a1 = $cableA->cores()->orderBy('id')->get()[0];
        $a2 = $cableA->cores()->orderBy('id')->get()[1];
        $b1 = $cableB->cores()->orderBy('id')->get()[0];
        $b2 = $cableB->cores()->orderBy('id')->get()[1];

        $this->service->createSplice($closure, $a1, $b1);

        // a1 is the `from` of the existing row
        try {
            $this->service->createSplice($closure, $a1, $b2);
            $this->fail('expected reject: a1 already spliced');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sudah tersambung', $e->getMessage());
        }

        // b1 is the `to` of the existing row — reusing it AT THIS NODE on
        // the `from` side must also fail
        try {
            $this->service->createSplice($closure, $b1, $a2);
            $this->fail('expected reject: b1 already spliced at this node');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sudah tersambung', $e->getMessage());
        }
    }

    public function test_revisi4_a_a_through_spliced_core_can_be_spliced_again_at_its_cables_other_end(): void
    {
        // otb -(cableA)- closure -(cableB)- odp -(cableC)- downstream
        [$closure, $cableA, $cableB] = $this->nodeWithTwoCables();
        $odp = Odp::withoutGlobalScopes()->findOrFail($cableB->to_id); // cableB runs closure -> odp

        $downstream = FiberNode::factory()->create(['tenant_id' => $closure->tenant_id, 'node_type' => 'odc', 'port_count' => null]);
        $cableC = app(FiberTopologyService::class)->createCable([
            'tenant_id' => $closure->tenant_id,
            'from_type' => Odp::class, 'from_id' => $odp->id,
            'to_type' => FiberNode::class, 'to_id' => $downstream->id,
            'total_cores' => 4, 'tube_count' => 2, 'cores_per_tube' => 2,
        ]);

        $aCore = $cableA->cores()->first();
        $bCore = $cableB->cores()->first();
        $cCore = $cableC->cores()->first();

        // hop 1 — splice at the closure: cableA (masuk) -> cableB (keluar)
        $this->service->createSplice($closure, $aCore, $bCore);

        // hop 2 — at the ODP, cableB is masuk, cableC is keluar. bCore is
        // already `to_` in the closure splice; that must NOT block it here.
        $splice = $this->service->createSplice($odp, $bCore->fresh(), $cCore);

        $this->assertDatabaseHas('fiber_core_splices', ['id' => $splice->id, 'from_fiber_core_id' => $bCore->id]);
        $this->assertSame(2, FiberCoreSplice::count());
    }

    public function test_rejects_a_node_that_no_cable_of_the_core_touches(): void
    {
        [$closure, $cableA, $cableB] = $this->nodeWithTwoCables();
        $a = $cableA->cores()->first();
        $b = $cableB->cores()->first();

        $stranger = FiberNode::factory()->create(['tenant_id' => $closure->tenant_id, 'node_type' => 'odc', 'port_count' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('terhubung ke titik ini');
        $this->service->createSplice($stranger, $a, $b);
    }

    public function test_rejects_negative_loss(): void
    {
        [$closure, $cableA, $cableB] = $this->nodeWithTwoCables();

        $this->expectException(InvalidArgumentException::class);
        $this->service->createSplice($closure, $cableA->cores()->first(), $cableB->cores()->first(), -1.0);
    }

    public function test_delete_splice_frees_both_cores(): void
    {
        [$closure, $cableA, $cableB] = $this->nodeWithTwoCables();
        $a = $cableA->cores()->first();
        $b = $cableB->cores()->first();

        $splice = $this->service->createSplice($closure, $a, $b);
        $this->service->deleteSplice($splice);

        $this->assertDatabaseMissing('fiber_core_splices', ['id' => $splice->id]);
        // cores are now re-spliceable
        $this->service->createSplice($closure, $a, $b);
        $this->assertSame(1, $this->service->splicesForNode($closure->fresh())->count());
    }

    /**
     * A Closure with TWO incoming cables (both `to` = closure) and TWO
     * outgoing (both `from` = closure) — for the direction guard.
     *
     * @return array{0: FiberNode, 1: FiberCable, 2: FiberCable, 3: FiberCable, 4: FiberCable}
     */
    private function closureWithTwoInTwoOut(): array
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));

        $closure = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'closure', 'port_count' => null]);
        $topo = app(FiberTopologyService::class);

        $mk = function (bool $incoming) use ($tenant, $closure, $topo) {
            $other = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'odc', 'port_count' => null]);

            return $topo->createCable([
                'tenant_id' => $tenant->id,
                'from_type' => FiberNode::class, 'from_id' => $incoming ? $other->id : $closure->id,
                'to_type' => FiberNode::class, 'to_id' => $incoming ? $closure->id : $other->id,
                'total_cores' => 2, 'tube_count' => 1, 'cores_per_tube' => 2,
            ]);
        };

        return [$closure, $mk(true), $mk(true), $mk(false), $mk(false)];
    }

    public function test_rejects_a_splice_between_two_incoming_cables(): void
    {
        [$closure, $in1, $in2] = $this->closureWithTwoInTwoOut();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('kabel masuk dengan kabel keluar');
        $this->service->createSplice($closure, $in1->cores()->first(), $in2->cores()->first());
    }

    public function test_rejects_a_splice_between_two_outgoing_cables(): void
    {
        [$closure, , , $out1, $out2] = $this->closureWithTwoInTwoOut();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('kabel masuk dengan kabel keluar');
        $this->service->createSplice($closure, $out1->cores()->first(), $out2->cores()->first());
    }

    public function test_accepts_a_splice_between_an_incoming_and_an_outgoing_cable_either_order(): void
    {
        [$closure, $in1, , $out1] = $this->closureWithTwoInTwoOut();

        // incoming -> outgoing
        $s1 = $this->service->createSplice($closure, $in1->cores()->orderBy('id')->get()[0], $out1->cores()->orderBy('id')->get()[0]);
        // outgoing -> incoming (reversed argument order still fine)
        $s2 = $this->service->createSplice($closure, $out1->cores()->orderBy('id')->get()[1], $in1->cores()->orderBy('id')->get()[1]);

        $this->assertDatabaseHas('fiber_core_splices', ['id' => $s1->id]);
        $this->assertDatabaseHas('fiber_core_splices', ['id' => $s2->id]);
    }

    public function test_works_at_an_odp_node_polymorphic(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));

        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $up = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'closure', 'port_count' => null]);
        $down = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'closure', 'port_count' => null]);

        $topo = app(FiberTopologyService::class);
        $in = $topo->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => FiberNode::class, 'from_id' => $up->id,
            'to_type' => Odp::class, 'to_id' => $odp->id,
            'total_cores' => 2, 'tube_count' => 1, 'cores_per_tube' => 2,
        ]);
        $out = $topo->createCable([
            'tenant_id' => $tenant->id,
            'from_type' => Odp::class, 'from_id' => $odp->id,
            'to_type' => FiberNode::class, 'to_id' => $down->id,
            'total_cores' => 2, 'tube_count' => 1, 'cores_per_tube' => 2,
        ]);

        $splice = $this->service->createSplice($odp, $in->cores()->first(), $out->cores()->first());

        $this->assertDatabaseHas('fiber_core_splices', [
            'id' => $splice->id,
            'splice_node_type' => Odp::class,
            'splice_node_id' => $odp->id,
        ]);
        $this->assertSame(1, $this->service->splicesForNode($odp->fresh())->count());
    }
}
