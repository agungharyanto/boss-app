<?php

namespace Database\Factories;

use App\Models\Odp;
use App\Models\Reseller;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Odp>
 */
class OdpFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'reseller_id' => null,
            'code' => 'ODP-'.$this->faker->unique()->numerify('####'),
            'name' => 'ODP '.$this->faker->streetName(),
            // Random point roughly within the Jakarta area — precise
            // location doesn't matter for Haversine distance-ordering
            // tests, only relative distance between rows does.
            'latitude' => $this->faker->latitude(-6.4, -6.0),
            'longitude' => $this->faker->longitude(106.6, 107.0),
            'total_ports' => 8,
            'notes' => null,
        ];
    }

    /**
     * tenant_id always derived from the given reseller's own tenant_id —
     * never an independent random tenant, same convention as
     * WhatsappSessionFactory::forReseller().
     */
    public function forReseller(Reseller $reseller): static
    {
        return $this->state(fn () => [
            'reseller_id' => $reseller->id,
            'tenant_id' => $reseller->tenant_id,
        ]);
    }
}
