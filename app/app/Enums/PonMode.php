<?php

namespace App\Enums;

use InvalidArgumentException;

/**
 * Mode PON OLT — diturunkan dari vendor OLT (keputusan #5 ZTP v0.23.8,
 * docs/omci/ztp-dynamic-config-design.md): TIDAK ada tabel baru / probe
 * device, cukup pemetaan vendor_key → mode di kode.
 */
enum PonMode: string
{
    case Gpon = 'gpon';
    case Epon = 'epon';

    public static function fromVendorKey(string $vendorKey): self
    {
        return match ($vendorKey) {
            'hsgq_e04id' => self::Epon,
            'hsgq_g02id', 'zte_c300' => self::Gpon,
            default => throw new InvalidArgumentException(
                "Tidak ada pemetaan PON mode untuk vendor OLT: \"{$vendorKey}\"."
            ),
        };
    }
}
