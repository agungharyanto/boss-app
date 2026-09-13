<?php

namespace App\Services\Network;

use App\Models\CpeDevice;
use App\Models\ModemType;

/**
 * v0.12.5 (rename dari WanConfigTemplateSuggestionService — dipisah dari
 * urusan Template sama sekali, sekarang murni "device ini pakai Tipe
 * Modem apa") — auto-suggest Tipe Modem untuk sebuah device, BEST-EFFORT,
 * TIDAK PERNAH garansi match. Dipanggil dari
 * App\Services\Network\CpeModemTypeAssignmentService::autoAssignIfUnset()
 * SEKALI SAJA, saat mengisi `cpe_devices.modem_type_id` pertama kali —
 * bukan re-detect ulang tiap kali Template butuh diresolve (lihat
 * WanConfigTemplateResolverService, yang membaca `modem_type_id` yang
 * SUDAH ter-assign, tidak pernah re-matching OUI sendiri).
 *
 * Match `cpe_devices.manufacturer` (dinormalisasi) ke
 * `modem_types.manufacturer_match_patterns`. Null kalau: manufacturer
 * kosong, tidak match Tipe Modem mana pun, atau match LEBIH DARI SATU
 * Tipe Modem sekaligus (ambigu, tidak ada "yang benar" otomatis).
 */
class ModemTypeSuggestionService
{
    public function suggestFor(CpeDevice $device): ?ModemType
    {
        $manufacturer = self::normalizeManufacturer($device->manufacturer);

        if ($manufacturer === null) {
            return null;
        }

        $matchingModemTypes = ModemType::withoutGlobalScopes()
            ->where('tenant_id', $device->tenant_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (ModemType $modemType) => in_array($manufacturer, $modemType->matchPatterns(), true));

        if ($matchingModemTypes->count() !== 1) {
            return null;
        }

        return $matchingModemTypes->first();
    }

    /**
     * Normalisasi kode OUI GenieACS (`cpe_devices.manufacturer`) supaya
     * perbandingan tidak sensitif kapital/spasi — OUI yang sama bisa
     * tersimpan dengan variasi kapitalisasi tergantung device/waktu
     * import (lihat investigasi v0.12.4). Sama normalisasi dengan
     * ModemType::matchPatterns().
     */
    public static function normalizeManufacturer(?string $manufacturer): ?string
    {
        $normalized = strtoupper(trim((string) $manufacturer));

        return $normalized === '' ? null : $normalized;
    }
}
