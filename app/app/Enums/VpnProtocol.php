<?php

namespace App\Enums;

enum VpnProtocol: string
{
    case OpenVpn = 'openvpn';
    case WireGuard = 'wireguard';
    case L2tpIpsec = 'l2tp_ipsec';

    public function label(): string
    {
        return match ($this) {
            self::OpenVpn => 'OpenVPN',
            self::WireGuard => 'WireGuard',
            self::L2tpIpsec => 'L2TP/IPsec',
        };
    }
}
