<?php

namespace Database\Factories;

use App\Models\WorkOrder;
use App\Models\WorkOrderModemUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrderModemUnit>
 */
class WorkOrderModemUnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'technician_id' => null,
            'serial_number' => 'TESTSN'.$this->faker->unique()->numerify('########'),
            'mac_address' => $this->faker->unique()->macAddress(),
        ];
    }
}
