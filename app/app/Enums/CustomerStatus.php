<?php

namespace App\Enums;

enum CustomerStatus: string
{
    case Prospek = 'prospek';
    case Aktif = 'aktif';
    case Suspend = 'suspend';
    case NonAktif = 'non_aktif';
    case Blacklist = 'blacklist';

    /**
     * Aturan transisi lifecycle pelanggan:
     * prospek -> aktif (satu arah, tidak bisa kembali ke prospek)
     * aktif <-> suspend <-> non_aktif (bebas bolak-balik antar tiga status ini)
     * status manapun -> blacklist (terminal, tidak ada jalan keluar)
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        if ($this === self::Blacklist) {
            return false;
        }

        if ($target === self::Blacklist) {
            return true;
        }

        return match ($this) {
            self::Prospek => $target === self::Aktif,
            self::Aktif, self::Suspend, self::NonAktif => in_array($target, [self::Aktif, self::Suspend, self::NonAktif], true),
            self::Blacklist => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Prospek => 'Prospek',
            self::Aktif => 'Aktif',
            self::Suspend => 'Suspend',
            self::NonAktif => 'Non-aktif',
            self::Blacklist => 'Blacklist',
        };
    }
}
