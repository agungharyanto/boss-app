<?php

namespace Database\Factories;

use App\Enums\NetworkProfileGroupType;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetworkProfileGroup>
 */
class NetworkProfileGroupFactory extends Factory
{
    public function definition(): array
    {
        // Attribute order matters here — see CustomerIpPoolFactory's own
        // docblock for the full explanation (Laravel resolves a factory's
        // closure attributes in ARRAY ORDER, not by name). nas_id must
        // come before BOTH customer_ip_pool_id and tenant_id below, since
        // both of their closures read $attributes['nas_id'].
        return [
            'nas_id' => Nas::factory(),
            'customer_ip_pool_id' => fn (array $attributes) => CustomerIpPool::factory()->create(['nas_id' => $attributes['nas_id']])->id,
            'tenant_id' => fn (array $attributes) => Nas::withoutGlobalScopes()->find($attributes['nas_id'])?->tenant_id,
            'name' => 'Grup-'.$this->faker->unique()->numerify('###'),
            'type' => NetworkProfileGroupType::Ppp,
            'dns_primary' => '8.8.8.8',
            'dns_secondary' => '8.8.4.4',
            'parent_queue' => null,
            'is_active' => true,
        ];
    }
}
