<?php

namespace App\Enums;

/**
 * Mode WAN Internet template ZTP (v0.23.8.3). PPPoE = production-ready
 * (terbukti Dahlia/E04ID). DHCP & Static: skema+UI dibangun TAPI logic tulis
 * ke device BELUM diverifikasi hardware (keputusan #4) — lihat
 * untestedOnHardware() + kolom `untested_on_hardware`.
 */
enum WanMode: string
{
    case Pppoe = 'pppoe';
    case Dhcp = 'dhcp';
    case Static = 'static';

    /**
     * true = logic tulis mode ini BELUM diverifikasi terhadap device nyata
     * (dhcp/static). UI wajib menampilkan peringatan; JANGAN diklaim
     * production-ready sampai diuji.
     */
    public function untestedOnHardware(): bool
    {
        return match ($this) {
            self::Pppoe => false,
            self::Dhcp, self::Static => true,
        };
    }
}
