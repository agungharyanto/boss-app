<?php

namespace Database\Factories;

use App\Enums\WorkOrderPhotoType;
use App\Models\WorkOrder;
use App\Models\WorkOrderPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrderPhoto>
 */
class WorkOrderPhotoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'type' => WorkOrderPhotoType::Odp,
            'file_path' => 'work-order-photos/test/'.$this->faker->uuid().'.jpg',
            'uploaded_at' => now(),
        ];
    }

    public function forWorkOrder(WorkOrder $workOrder): static
    {
        return $this->state(fn () => ['work_order_id' => $workOrder->id]);
    }

    public function ofType(WorkOrderPhotoType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }
}
