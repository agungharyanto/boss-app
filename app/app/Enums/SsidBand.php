<?php

namespace App\Enums;

/**
 * Band radio SSID template ZTP (v0.23.8.4). Dipakai (bersama WifiRole) untuk
 * memetakan ke nomor instance WLANConfiguration per merek CPE di adapter.
 */
enum SsidBand: string
{
    case Band24 = '2.4g';
    case Band5 = '5g';
}
