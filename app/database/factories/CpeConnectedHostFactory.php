<?php

namespace Database\Factories;

use App\Models\CpeConnectedHost;
use App\Models\CpeDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CpeConnectedHost>
 */
class CpeConnectedHostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cpe_device_id' => CpeDevice::factory(),
            'tenant_id' => fn (array $attributes) => CpeDevice::withoutGlobalScopes()->find($attributes['cpe_device_id'])?->tenant_id,
            'reseller_id' => fn (array $attributes) => CpeDevice::withoutGlobalScopes()->find($attributes['cpe_device_id'])?->reseller_id,
            'mac_address' => strtoupper($this->faker->unique()->macAddress()),
            'hostname' => $this->faker->userName(),
            'ip_address' => $this->faker->localIpv4(),
            'is_active' => true,
            'first_seen_at' => now()->subDays(3),
            'last_seen_at' => now(),
        ];
    }
}
