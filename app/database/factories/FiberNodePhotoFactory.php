<?php

namespace Database\Factories;

use App\Models\FiberNode;
use App\Models\FiberNodePhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiberNodePhoto>
 */
class FiberNodePhotoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => FiberNode::class,
            'owner_id' => FiberNode::factory(),
            'photo_path' => 'fiber-node-photos/'.$this->faker->uuid().'.jpg',
            'caption' => null,
            'taken_at' => now(),
        ];
    }
}
