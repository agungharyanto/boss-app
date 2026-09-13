<?php

namespace Database\Factories;

use App\Models\ModemType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModemType>
 */
class ModemTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->unique()->words(3, true).' Modem',
            'is_active' => true,
        ];
    }
}
