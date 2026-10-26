<?php

namespace App\Enums;

/**
 * Peran WiFi yang di-ikat sebuah WAN (ZTP v0.23.8.3) — BUKAN nomor instance
 * SSID hardcode. Adapter CPE (per merek) yang menerjemahkan peran → instance
 * WLANConfiguration (mis. CT-COM: primary=1&5, guest=4&8).
 */
enum WifiRole: string
{
    case PrimaryWifi = 'primary_wifi';
    case GuestWifi = 'guest_wifi';
    case None = 'none';
}
