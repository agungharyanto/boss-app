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

    /**
     * The reserved .1 address of $cidr — the VPN node's own tun0/wg0
     * endpoint, same reservation `usableHostAddresses()` excludes above.
     * v0.7.3: MikrotikScriptGenerator::wireGuardScript() needs this as an
     * explicit `allowed-address` entry on the router's peer — traffic a
     * VPN node MASQUERADEs onto its own tunnel identity before forwarding
     * into a NAS's management subnet (see docker/wireguard/entrypoint.sh's
     * TR069_MANAGEMENT_SUBNET MASQUERADE rule) arrives at the router
     * sourced as THIS address, not the originating service's real IP —
     * without it in allowed-address, WireGuard's own cryptokey routing
     * drops the packet before RouterOS's firewall/routing ever sees it.
     */
    public static function gatewayAddress(string $cidr): string
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

        return long2ip($networkLong + 1);
    }
}
