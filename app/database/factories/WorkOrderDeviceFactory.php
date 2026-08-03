<?php

namespace Database\Factories;

use App\Enums\WorkOrderDeviceType;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrderDevice>
 */
class WorkOrderDeviceFactory extends Factory
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
            'device_type' => WorkOrderDeviceType::Ont,
            'mac_address' => strtoupper($this->faker->macAddress()),
            'serial_number' => $this->faker->unique()->bothify('SN#######??'),
            'scanned_at' => now(),
        ];
    }

    public function forWorkOrder(WorkOrder $workOrder): static
    {
        return $this->state(fn () => ['work_order_id' => $workOrder->id]);
    }
}
