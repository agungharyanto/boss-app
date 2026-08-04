<?php

namespace Database\Factories;

use App\Enums\VpnAccountStatus;
use App\Models\Nas;
use App\Models\VpnAccount;
use App\Models\VpnServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VpnAccount>
 */
class VpnAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nas_id' => Nas::factory(),
            'vpn_server_id' => VpnServer::factory(),
            'protocol' => 'openvpn',
            'username' => 'nas-'.$this->faker->unique()->numberBetween(1000, 999999),
            'password' => null,
            'internal_ip' => $this->faker->unique()->localIpv4(),
            'cert_serial' => strtoupper($this->faker->unique()->bothify('################')),
            'status' => VpnAccountStatus::Active,
            'issued_at' => now(),
            'revoked_at' => null,
            'connected_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => VpnAccountStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }
}
