<?php

namespace Database\Factories;

use App\Enums\CpeDeviceStatus;
use App\Enums\Tr069Root;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\Reseller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CpeDevice>
 */
class CpeDeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            // Must match the parent customer's own tenant_id/reseller_id,
            // never an independent random tenant — same convention as
            // CustomerContactFactory.
            'tenant_id' => fn (array $attributes) => Customer::withoutGlobalScopes()->find($attributes['customer_id'])?->tenant_id,
            'reseller_id' => fn (array $attributes) => Customer::withoutGlobalScopes()->find($attributes['customer_id'])?->reseller_id,
            'work_order_device_id' => null,
            'genieacs_device_id' => $this->faker->unique()->regexify('[A-F0-9]{6}-[A-Za-z0-9]{10,20}-[A-Za-z0-9]{8,12}'),
            'manufacturer' => $this->faker->randomElement(['Huawei', 'ZTE', 'Nokia']),
            'model_name' => $this->faker->bothify('HG####??'),
            'serial_number' => $this->faker->unique()->bothify('SN##########'),
            'tr069_root' => Tr069Root::InternetGatewayDevice,
            'status' => CpeDeviceStatus::Online,
            'last_inform_at' => now(),
            'bound_at' => now(),
        ];
    }

    /**
     * Creates a Customer under the given reseller first, then derives from
     * it — never sets reseller_id independently of a real customer row, for
     * the same "avoid inconsistent cross-tenant fixtures" reasoning as
     * CustomerContactFactory.
     */
    public function forReseller(Reseller $reseller): static
    {
        return $this->state(function () use ($reseller) {
            $customer = Customer::factory()->create([
                'tenant_id' => $reseller->tenant_id,
                'reseller_id' => $reseller->id,
            ]);

            return [
                'customer_id' => $customer->id,
                'tenant_id' => $reseller->tenant_id,
                'reseller_id' => $reseller->id,
            ];
        });
    }

    public function pendingFirstConnect(): static
    {
        return $this->state(fn () => [
            'genieacs_device_id' => null,
            'manufacturer' => null,
            'model_name' => null,
            'tr069_root' => null,
            'status' => CpeDeviceStatus::PendingFirstConnect,
            'last_inform_at' => null,
        ]);
    }
}
