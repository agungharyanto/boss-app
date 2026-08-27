<?php

namespace App\Enums;

/**
 * v0.14.3.1 — real bug found by Agung: a Grup Profil form (Tipe=PPP)
 * could select an IP Pool whose own name obviously read as Hotspot-only
 * ("Hotspot-10Mbps") — nothing separated the two. This tags a
 * CustomerIpPool with its intended usage so the Grup Profil form's own
 * pool dropdown can filter correctly (see NetworkProfileGroupIndex's own
 * poolOptionsForNas()/editPoolOptionsForNas() computation).
 */
enum CustomerIpPoolUsageType: string
{
    case Ppp = 'ppp';
    case Hotspot = 'hotspot';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Ppp => 'PPP',
            self::Hotspot => 'Hotspot',
            self::General => 'Umum',
        };
    }

    /**
     * True if a pool tagged with this usage type is selectable for a Grup
     * Profil of $groupType — General is selectable for BOTH types
     * (matches "Umum" muncul di keduanya" from the sprint brief); Ppp/
     * Hotspot are only selectable for their own matching type.
     */
    public function isCompatibleWith(NetworkProfileGroupType $groupType): bool
    {
        if ($this === self::General) {
            return true;
        }

        return match ($groupType) {
            NetworkProfileGroupType::Ppp => $this === self::Ppp,
            NetworkProfileGroupType::Hotspot => $this === self::Hotspot,
        };
    }
}
