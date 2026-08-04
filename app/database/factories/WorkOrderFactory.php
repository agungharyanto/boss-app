<?php

namespace Database\Factories;

use App\Enums\WorkOrderStatus;
use App\Models\Subscription;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // tenant_id/reseller_id/customer_id always derived from the
        // subscription — never independently random, same convention as
        // every other denormalized-reseller_id factory in this codebase.
        $subscription = Subscription::factory()->create();

        return [
            'tenant_id' => $subscription->tenant_id,
            'reseller_id' => $subscription->reseller_id,
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer_id,
            'technician_id' => null,
            'odp_id' => null,
            'odp_port_id' => null,
            'status' => WorkOrderStatus::PendingOdpCheck,
            'equipment_ready' => false,
            'scheduled_at' => null,
            'completed_at' => null,
            'notes' => null,
        ];
    }

    public function forSubscription(Subscription $subscription): static
    {
        return $this->state(fn () => [
            'tenant_id' => $subscription->tenant_id,
            'reseller_id' => $subscription->reseller_id,
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer_id,
        ]);
    }

    public function pendingVerification(): static
    {
        return $this->state(fn () => ['status' => WorkOrderStatus::PendingVerification]);
    }

    public function ready(): static
    {
        return $this->state(fn () => ['status' => WorkOrderStatus::Ready, 'equipment_ready' => true]);
    }

    public function assigned(): static
    {
        return $this->state(fn () => ['status' => WorkOrderStatus::Assigned, 'equipment_ready' => true]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => WorkOrderStatus::InProgress, 'equipment_ready' => true]);
    }
}
