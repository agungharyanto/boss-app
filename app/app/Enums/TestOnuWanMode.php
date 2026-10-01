<?php

namespace App\Enums;

/**
 * v0.23.5 (Opsi B) — WAN mode untuk konfigurasi WAN test. HANYA `Pppoe`
 * yang flow eksekusinya diimplementasikan v1 (template OMCI delete+recreate
 * + baris `pppoe ... nat enable user ... password ...` terbukti). `Dhcp`/
 * `Static`/`Webpage` disimpan sebagai pilihan (muncul di UI, disabled +
 * "Segera hadir") TAPI apply-nya belum ada — Service menolak dengan jelas.
 * Lihat docs/omci/onu-test-ui-design.md §2/§5.
 */
enum TestOnuWanMode: string
{
    case Pppoe = 'pppoe';
    case Dhcp = 'dhcp';
    case Static = 'static';
    case Webpage = 'webpage';

    public function label(): string
    {
        return match ($this) {
            self::Pppoe => 'PPPoE',
            self::Dhcp => 'DHCP',
            self::Static => 'Static IP',
            self::Webpage => 'Setup via ONU webpage',
        };
    }

    public function isSupported(): bool
    {
        return $this === self::Pppoe;
    }
}
