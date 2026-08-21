<?php

namespace Tests\Unit\Support;

use App\Support\CidrRange;
use InvalidArgumentException;
use Tests\TestCase;

class CidrRangeTest extends TestCase
{
    public function test_slash_24_yields_253_usable_addresses_excluding_network_broadcast_and_gateway(): void
    {
        $addresses = CidrRange::usableHostAddresses('172.23.194.0/24');

        $this->assertCount(253, $addresses);
        $this->assertNotContains('172.23.194.0', $addresses);
        $this->assertNotContains('172.23.194.1', $addresses); // reserved for the VPN server's own tun0
        $this->assertNotContains('172.23.194.255', $addresses);
        $this->assertContains('172.23.194.2', $addresses);
        $this->assertContains('172.23.194.254', $addresses);
    }

    public function test_slash_29_yields_5_usable_addresses(): void
    {
        $addresses = CidrRange::usableHostAddresses('172.23.200.0/29');

        // /29 = 8 addresses total, minus network(.0)/broadcast(.7)/gateway(.1) = 5
        $this->assertCount(5, $addresses);
        $this->assertSame(
            ['172.23.200.2', '172.23.200.3', '172.23.200.4', '172.23.200.5', '172.23.200.6'],
            $addresses
        );
    }

    public function test_invalid_cidr_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CidrRange::usableHostAddresses('not-a-cidr');
    }

    public function test_gateway_address_is_the_reserved_dot_one(): void
    {
        $this->assertSame('172.23.194.1', CidrRange::gatewayAddress('172.23.194.0/24'));
        $this->assertSame('172.23.200.1', CidrRange::gatewayAddress('172.23.200.0/29'));
    }

    public function test_gateway_address_invalid_cidr_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CidrRange::gatewayAddress('not-a-cidr');
    }

    /**
     * v0.8.1 — one dedicated /30 per NAS, replacing the single shared
     * gateway gatewayAddress() above returns. Block #0's gateway/router
     * pair must land exactly on the values Agung specified when this was
     * designed: 172.23.195.0/30 → .1 (gateway) / .2 (router).
     */
    public function test_wireguard_nas_block_zero_is_the_first_slash_30_of_the_subnet(): void
    {
        $block = CidrRange::wireguardNasBlock('172.23.195.0/24', 0);

        $this->assertSame(['gateway' => '172.23.195.1', 'router' => '172.23.195.2'], $block);
    }

    public function test_wireguard_nas_block_one_is_the_next_slash_30(): void
    {
        $block = CidrRange::wireguardNasBlock('172.23.195.0/24', 1);

        $this->assertSame(['gateway' => '172.23.195.5', 'router' => '172.23.195.6'], $block);
    }

    public function test_wireguard_nas_block_sequence_never_overlaps_across_many_indices(): void
    {
        $seen = [];

        foreach (range(0, 20) as $index) {
            $block = CidrRange::wireguardNasBlock('172.23.195.0/24', $index);

            foreach ([$block['gateway'], $block['router']] as $ip) {
                $this->assertArrayNotHasKey($ip, $seen, "IP {$ip} reused by block #{$index}, already claimed by an earlier block.");
                $seen[$ip] = $index;
            }
        }
    }

    public function test_wireguard_nas_block_exhaustion_throws(): void
    {
        // 172.23.195.0/24 has 64 possible /30 blocks (0-63) — index 64
        // would spill past the subnet's own broadcast address.
        $this->expectException(InvalidArgumentException::class);

        CidrRange::wireguardNasBlock('172.23.195.0/24', 64);
    }

    public function test_wireguard_nas_block_invalid_cidr_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CidrRange::wireguardNasBlock('not-a-cidr', 0);
    }

    public function test_wireguard_nas_block_negative_index_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CidrRange::wireguardNasBlock('172.23.195.0/24', -1);
    }
}
