<?php

namespace App\Enums;

/**
 * Root path data model TR-069 — beda generasi device pakai root berbeda,
 * jadi query parameter (GenieAcsClientService) perlu tahu yang mana yang
 * cocok sebelum menyusun path parameter.
 */
enum Tr069Root: string
{
    case InternetGatewayDevice = 'InternetGatewayDevice';
    case Device = 'Device';
}
