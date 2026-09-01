<?php

namespace Database\Factories;

use App\Models\Odp;
use App\Models\SalesRouteNote;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesRouteNote>
 */
class SalesRouteNoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'customer_id' => null,
            'prospect_name' => $this->faker->name(),
            'prospect_address' => $this->faker->streetAddress(),
            'from_latitude' => $this->faker->latitude(-6.4, -6.0),
            'from_longitude' => $this->faker->longitude(106.6, 107.0),
            'target_odp_id' => Odp::factory(),
            'route_label' => 'Rekomendasi',
            'route_geometry' => ['type' => 'LineString', 'coordinates' => [[106.8, -6.2], [106.81, -6.21]]],
            'distance_meters' => $this->faker->numberBetween(120, 3500),
            'is_straight_line_estimate' => false,
            'note' => null,
            'created_by' => null,
        ];
    }
}
