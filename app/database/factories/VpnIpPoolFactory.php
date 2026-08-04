<?php

namespace Database\Factories;

use App\Enums\VpnIpPoolStatus;
use App\Models\VpnIpPool;
use App\Models\VpnServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VpnIpPool>
 */
class VpnIpPoolFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vpn_server_id' => VpnServer::factory(),
            'ip_address' => $this->faker->unique()->localIpv4(),
            'status' => VpnIpPoolStatus::Available,
            'vpn_account_id' => null,
        ];
    }

    public function forServer(VpnServer $vpnServer): static
    {
        return $this->state(fn () => ['vpn_server_id' => $vpnServer->id]);
    }
}
