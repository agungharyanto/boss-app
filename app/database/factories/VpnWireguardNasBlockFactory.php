<?php

namespace Database\Factories;

use App\Models\Nas;
use App\Models\VpnServer;
use App\Models\VpnWireguardNasBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VpnWireguardNasBlock>
 */
class VpnWireguardNasBlockFactory extends Factory
{
    public function definition(): array
    {
        $blockIndex = $this->faker->unique()->numberBetween(0, 60);
        $base = ip2long('172.23.195.0') + ($blockIndex * 4);

        return [
            'nas_id' => Nas::factory(),
            'vpn_server_id' => VpnServer::factory(),
            'block_index' => $blockIndex,
            'gateway_ip' => long2ip($base + 1),
            'router_ip' => long2ip($base + 2),
        ];
    }
}
