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
}
