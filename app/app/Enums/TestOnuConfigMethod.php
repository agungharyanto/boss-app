<?php

namespace App\Enums;

/**
 * v0.23.5 (Opsi B) — metode provisioning WAN test:
 * - `Omci`: template OMCI terbukti (delete+recreate ONU dengan VLAN/PPPoE
 *   sesuai paket) via TestCredentialSyncService.
 * - `Tr069`: reuse mekanisme RemoteWanConfig/GenieACS existing (pola
 *   Test-1/Test-2), BUKAN baris `pppoe` OMCI.
 *
 * TERPISAH dari Remote Management (tr069-mgmt + ip-host2 + VLAN 9) yang
 * SELALU diterapkan di setiap aktivasi OMCI — `config_method` hanya soal
 * provisioning WAN itu sendiri, bukan kanal manajemen ACS.
 */
enum TestOnuConfigMethod: string
{
    case Omci = 'omci';
    case Tr069 = 'tr069';

    public function label(): string
    {
        return match ($this) {
            self::Omci => 'OMCI',
            self::Tr069 => 'TR-069',
        };
    }
}
