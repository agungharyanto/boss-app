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
        // v0.6.5: auth_port/acct_port/coa_port are globally unique columns
        // now (NasPortAllocatorService in production) — a fixed coa_port
        // default (the old 3799-for-every-row v0.6.1 placeholder) would
        // collide the instant a second factory-made NAS existed. Faked
        // here as an already-allocated block (base/+1/+2, mirroring the
        // real allocator's step-of-10 spacing) so every factory NAS looks
        // like production post-v0.6.5 state by default — a fresh,
        // never-provisioned NAS is the exception (provisionedPorts(false)),
        // not the common case, unlike mikrotik_ip which stays the opposite
        // way round.
        $basePort = $this->faker->unique()->numberBetween(100, 50000) * 10;

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
            'auth_port' => $basePort,
            'acct_port' => $basePort + 1,
            'coa_port' => $basePort + 2,
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
     * mikrotik_ip. auth_port/acct_port/coa_port no longer need overriding
     * here — definition() already fakes an allocated, unique block for
     * every row (v0.6.5), mirroring real NasPortAllocatorService output.
     */
    public function provisioned(): static
    {
        return $this->state(fn () => [
            'mikrotik_ip' => $this->faker->unique()->localIpv4(),
        ]);
    }

    /**
     * A brand-new, never-allocated NAS — the actual pre-v0.6.5-allocator
     * edge case (auth_port/acct_port null; coa_port stays whatever
     * definition() faked — that column has always been NOT NULL with a
     * default, see the original nas migration, unlike auth_port/acct_port
     * which were nullable from v0.6.1 on). Rare in practice since
     * NasService::create() always allocates immediately, but needed to
     * test the allocator itself and any legacy-row handling.
     */
    public function unprovisionedPorts(): static
    {
        return $this->state(fn () => [
            'auth_port' => null,
            'acct_port' => null,
        ]);
    }
}
