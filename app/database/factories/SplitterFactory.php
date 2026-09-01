<?php

namespace Database\Factories;

use App\Models\FiberNode;
use App\Models\Splitter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Splitter>
 */
class SplitterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => FiberNode::class,
            'owner_id' => FiberNode::factory(),
            'ratio' => '1:8',
            'model' => null,
        ];
    }
}
