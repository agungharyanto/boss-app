<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\ToolType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ToolType>
 */
class ToolTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->unique()->words(2, true),
            'category' => null,
            'is_active' => true,
        ];
    }
}
