<?php

namespace Database\Factories;

use App\Enums\NasStatus;
use App\Models\Nas;
use App\Models\Reseller;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Nas>
 */
class NasFactory extends Factory
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
            'name' => 'NAS-'.$this->faker->unique()->numerify('###'),
            'description' => $this->faker->optional()->sentence(),
            // Null by default (pre-VPN-provisioning state, the v0.6.1
            // reality) — tests that need a reachable NAS use provisioned().
            'mikrotik_ip' => null,
            'api_port' => 8728,
            'api_username' => 'admin',
            'api_password' => $this->faker->password(),
            'radius_secret' => $this->faker->password(20),
            'auth_port' => null,
            'acct_port' => null,
            'coa_port' => 3799,
            'status' => NasStatus::Unknown,
            'last_ping_at' => null,
            'timezone' => 'Asia/Jakarta',
        ];
    }

    public function forReseller(Reseller $reseller): static
    {
        return $this->state(fn () => [
            'reseller_id' => $reseller->id,
            'tenant_id' => $reseller->tenant_id,
        ]);
    }

    /**
     * Simulates the post-v0.6.2 state where VPN provisioning has filled in
     * mikrotik_ip and the port allocator (v0.6.5) has assigned unique ports.
     */
    public function provisioned(): static
    {
        return $this->state(fn () => [
            'mikrotik_ip' => $this->faker->unique()->localIpv4(),
            'auth_port' => $this->faker->unique()->numberBetween(20000, 29999),
            'acct_port' => $this->faker->unique()->numberBetween(30000, 39999),
        ]);
    }
}
