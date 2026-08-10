<?php

namespace Database\Factories;

use App\Enums\CpeActionStatus;
use App\Enums\CpeActionType;
use App\Models\CpeActionLog;
use App\Models\CpeDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CpeActionLog>
 */
class CpeActionLogFactory extends Factory
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
            // Must match the parent device's own tenant_id/reseller_id,
            // never an independent random tenant — same convention as
            // CpeDeviceFactory itself derives from Customer.
            'tenant_id' => fn (array $attributes) => CpeDevice::withoutGlobalScopes()->find($attributes['cpe_device_id'])?->tenant_id,
            'reseller_id' => fn (array $attributes) => CpeDevice::withoutGlobalScopes()->find($attributes['cpe_device_id'])?->reseller_id,
            'performed_by' => User::factory(),
            'action_type' => CpeActionType::Reboot,
            'parameters' => null,
            'genieacs_task_id' => $this->faker->uuid(),
            'status' => CpeActionStatus::Delivered,
            'failed_reason' => null,
            'completed_at' => null,
        ];
    }
}
