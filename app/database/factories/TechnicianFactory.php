<?php

namespace Database\Factories;

use App\Enums\TechnicianStatus;
use App\Models\Reseller;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Technician>
 */
class TechnicianFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Eagerly created (not a lazy factory ref) so the technician's own
        // user_id belongs to the SAME tenant as the technician row itself —
        // User::factory() defaults to an independent random tenant_id,
        // which would otherwise silently create a cross-tenant mismatch.
        $tenant = Tenant::factory()->create();

        return [
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'user_id' => User::factory()->create(['tenant_id' => $tenant->id])->id,
            'name' => $this->faker->name(),
            'phone' => $this->faker->numerify('08##########'),
            'status' => TechnicianStatus::Active,
        ];
    }

    /**
     * Overrides both tenant_id AND user_id (a fresh user in the reseller's
     * own tenant) — definition()'s eagerly-created user would otherwise be
     * left pointing at the wrong (randomly generated) tenant.
     */
    public function forReseller(Reseller $reseller): static
    {
        return $this->state(fn () => [
            'reseller_id' => $reseller->id,
            'tenant_id' => $reseller->tenant_id,
            'user_id' => User::factory()->create(['tenant_id' => $reseller->tenant_id])->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => TechnicianStatus::Inactive]);
    }
}
