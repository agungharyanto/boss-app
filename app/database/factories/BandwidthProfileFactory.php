<?php

namespace Database\Factories;

use App\Models\BandwidthProfile;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BandwidthProfile>
 */
class BandwidthProfileFactory extends Factory
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
            'name' => $this->faker->unique()->words(2, true).' Profile',
            'upload_min' => 512,
            'upload_max' => 1024,
            'download_min' => 1024,
            'download_max' => 2048,
            'is_active' => true,
        ];
    }
}
