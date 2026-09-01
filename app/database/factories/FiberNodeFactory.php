<?php

namespace Database\Factories;

use App\Enums\FiberNodeType;
use App\Models\FiberNode;
use App\Models\Reseller;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiberNode>
 */
class FiberNodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'reseller_id' => null,
            'node_type' => FiberNodeType::Otb,
            'local_label' => 'FN-'.$this->faker->unique()->numerify('####'),
            'parent_type' => null,
            'parent_id' => null,
            'latitude' => $this->faker->latitude(-6.4, -6.0),
            'longitude' => $this->faker->longitude(106.6, 107.0),
            'loss_in_db' => null,
            'loss_out_db' => null,
            // v0.16.0 Langkah 6 — the default node_type is Otb, and an OTB
            // needs a port_count (required in FiberNodeForm); give it one
            // so a bare FiberNode::factory()->create() is a valid OTB.
            'port_count' => $this->faker->randomElement([8, 16, 24]),
            'notes' => null,
        ];
    }

    /**
     * tenant_id always derived from the given reseller's own tenant_id —
     * same convention as OdpFactory::forReseller().
     */
    public function forReseller(Reseller $reseller): static
    {
        return $this->state(fn () => [
            'reseller_id' => $reseller->id,
            'tenant_id' => $reseller->tenant_id,
        ]);
    }
}
