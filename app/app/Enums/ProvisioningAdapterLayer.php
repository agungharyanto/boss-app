<?php

namespace App\Enums;

/**
 * Dimensi adapter ZTP (v0.23.8, revisi dua-dimensi — section G
 * docs/omci/ztp-dynamic-config-design.md):
 *   Olt → operasi sisi-OLT (admisi/OMCI WAN/naming/save), per vendor OLT.
 *   Cpe → operasi sisi-CPE via TR-069 (WAN internet device-driven/bridge/
 *         SSID), per MEREK CPE, LINTAS semua OLT.
 */
enum ProvisioningAdapterLayer: string
{
    case Olt = 'olt';
    case Cpe = 'cpe';
}
