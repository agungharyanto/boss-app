<?php

namespace Database\Factories;

use App\Enums\VpnProtocol;
use App\Enums\VpnServerStatus;
use App\Models\VpnServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VpnServer>
 */
class VpnServerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hostname' => 'vpn-node-'.$this->faker->unique()->numerify('##'),
            'public_ip' => $this->faker->unique()->ipv4(),
            'port' => $this->faker->unique()->numberBetween(20000, 29999),
            'subnet_cidr' => '172.23.'.$this->faker->unique()->numberBetween(100, 250).'.0/24',
            'protocol' => VpnProtocol::OpenVpn,
            'max_clients' => 250,
            'current_clients' => 0,
            'status' => VpnServerStatus::Online,
            'is_active' => true,
        ];
    }
}
