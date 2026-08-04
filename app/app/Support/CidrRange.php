<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Pure IPv4 CIDR arithmetic — no DB/model dependency, used by
 * VpnServer::provisionIpPool() to expand vpn_servers.subnet_cidr into
 * individual usable host addresses.
 */
class CidrRange
{
    /**
     * Usable host addresses in $cidr, excluding the network address, the
     * broadcast address, and .1 (reserved for the VPN server's own tun0
     * endpoint address).
     *
     * @return list<string>
     */
    public static function usableHostAddresses(string $cidr): array
    {
        $parts = explode('/', $cidr, 2);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException("CIDR tidak valid: {$cidr}");
        }

        [$base, $prefix] = $parts;
        $prefix = (int) $prefix;

        if ($prefix < 0 || $prefix > 32 || ip2long($base) === false) {
            throw new InvalidArgumentException("CIDR tidak valid: {$cidr}");
        }

        $hostBits = 32 - $prefix;
        $networkLong = ip2long($base) & (-1 << $hostBits);
        $broadcastLong = $networkLong | ((1 << $hostBits) - 1);
        $reservedGatewayLong = $networkLong + 1; // .1 — VPN server's own tun0 address

        $addresses = [];
        for ($ip = $networkLong + 1; $ip < $broadcastLong; $ip++) {
            if ($ip === $reservedGatewayLong) {
                continue;
            }

            $addresses[] = long2ip($ip);
        }

        return $addresses;
    }
}
