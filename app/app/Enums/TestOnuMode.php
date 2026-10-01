<?php

namespace App\Enums;

/**
 * v0.23.5 (Opsi B) — mode ONU untuk konfigurasi WAN test. `Bridging`
 * DITUNDA (bridge OMCI belum pernah terbukti bekerja di codebase ini —
 * lihat docs/omci/onu-test-ui-design.md §0/§5). UI hanya menawarkan
 * `Routing` untuk v1; `Bridging` disediakan sebagai case supaya future
 * work tidak perlu migrasi, TAPI Service menolaknya ("belum didukung").
 */
enum TestOnuMode: string
{
    case Routing = 'routing';
    case Bridging = 'bridging';

    public function label(): string
    {
        return match ($this) {
            self::Routing => 'Routing',
            self::Bridging => 'Bridging (belum didukung)',
        };
    }

    public function isSupported(): bool
    {
        return $this === self::Routing;
    }
}
