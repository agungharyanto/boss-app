<?php

namespace App\Enums;

/**
 * Mode keamanan SSID template ZTP (v0.23.8.4). `open` = tanpa password (resep
 * OPEN terbukti: BeaconType=None dst). `wpa2` = password wajib.
 */
enum SsidSecurity: string
{
    case Open = 'open';
    case Wpa2 = 'wpa2';
}
