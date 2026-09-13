<?php

namespace App\Enums;

/**
 * v0.12.5 — asal-usul `cpe_devices.wan_config_template_id`: hasil
 * auto-suggest (WanConfigTemplateSuggestionService, best-effort) atau
 * override manual admin di Detail Perangkat CPE. Ditampilkan sebagai
 * badge supaya jelas mana yang bisa dipercaya "otomatis benar" vs mana
 * yang sudah dikonfirmasi manusia.
 */
enum WanConfigTemplateSource: string
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
