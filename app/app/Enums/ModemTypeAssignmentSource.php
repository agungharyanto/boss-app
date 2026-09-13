<?php

namespace App\Enums;

/**
 * v0.12.5 (rename dari WanConfigTemplateSource, sejalan dengan revisi
 * "Detail Perangkat CPE assign Tipe Modem langsung, bukan Template") —
 * asal-usul `cpe_devices.modem_type_id`: hasil auto-suggest (OUI
 * matching manufacturer -> modem_types.manufacturer_match_patterns,
 * lihat App\Services\Network\ModemTypeSuggestionService) atau override
 * manual admin di Detail Perangkat CPE. Ditampilkan sebagai badge supaya
 * jelas mana yang bisa dipercaya "otomatis benar" vs mana yang sudah
 * dikonfirmasi manusia.
 */
enum ModemTypeAssignmentSource: string
{
    case Auto = 'auto';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Auto-terdeteksi',
            self::Manual => 'Manual',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Auto => 'bg-blue-100 text-blue-700',
            self::Manual => 'bg-purple-100 text-purple-700',
        };
    }
}
