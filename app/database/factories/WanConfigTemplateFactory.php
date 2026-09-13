<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\WanConfigTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WanConfigTemplate>
 */
class WanConfigTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->unique()->words(2, true).' Template',
            'modem_type_id' => null,
            'enabled' => false,
            'wan1_enabled' => true,
            'wan1_vlan' => 1000,
            'wan1_pppoe_username' => 'default',
            'wan1_pppoe_password' => 'default',
            'wan2_enabled' => false,
            'wan2_vlan' => 1200,
        ];
    }
}
