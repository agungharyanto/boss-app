<?php

namespace Database\Factories;

use App\Models\FiberCable;
use App\Models\FiberNode;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiberCable>
 */
class FiberCableFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'from_type' => FiberNode::class,
            'from_id' => FiberNode::factory(),
            'to_type' => FiberNode::class,
            'to_id' => FiberNode::factory(),
            'total_cores' => 12,
            'tube_count' => 2,
            'cores_per_tube' => 6,
        ];
    }
}
