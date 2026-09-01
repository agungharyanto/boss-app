<?php

namespace Database\Factories;

use App\Models\FiberCable;
use App\Models\FiberCore;
use App\Models\FiberCorePortLog;
use App\Models\FiberNode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiberCorePortLog>
 */
class FiberCorePortLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $node = FiberNode::factory();

        return [
            'fiber_core_id' => FiberCore::factory()->for(FiberCable::factory()),
            'fiber_node_id' => $node,
            'performed_by' => User::factory(),
            'old_port_number' => null,
            'new_port_number' => $this->faker->numberBetween(1, 16),
            'old_olt_label' => null,
            'new_olt_label' => null,
        ];
    }
}
