<?php

namespace Database\Factories;

use App\Enums\WhatsappSessionStatus;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\WhatsappSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappSession>
 */
class WhatsappSessionFactory extends Factory
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
            'reseller_id' => null,
            'phone_number' => null,
            'status' => WhatsappSessionStatus::QrPending,
            'qr_code_data' => null,
            'last_connected_at' => null,
            'last_disconnected_at' => null,
        ];
    }

    /**
     * A reseller-owned session. tenant_id always derived from the given
     * reseller's own tenant_id — never an independent random tenant, same
     * convention as CustomerContactFactory (see CLAUDE.md).
     */
    public function forReseller(Reseller $reseller): static
    {
        return $this->state(fn () => [
            'reseller_id' => $reseller->id,
            'tenant_id' => $reseller->tenant_id,
        ]);
    }

    public function connected(): static
    {
        return $this->state(fn () => [
            'status' => WhatsappSessionStatus::Connected,
            'phone_number' => $this->faker->numerify('628##########'),
            'last_connected_at' => now(),
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn () => [
            'status' => WhatsappSessionStatus::Disconnected,
            'last_disconnected_at' => now(),
        ]);
    }
}
