<?php

namespace App\Services\Network;

use App\Models\CpeDevice;
use App\Models\ModemType;
use App\Models\WanConfigTemplate;

/**
 * v0.12.5 (koreksi arsitektur, kembali ke matrix — dikonfirmasi Agung dari
 * klarifikasi chat planning) — auto-suggest Template Konfig CPE untuk
 * sebuah device, BEST-EFFORT, TIDAK PERNAH garansi match. Dipanggil
 * ON-DEMAND dari Detail Perangkat CPE (bukan dari alur binding v0.7.5/
 * CpeBindingService) — sama keputusan arsitektur seperti sebelumnya
 * (freshness terhadap katalog + menghindari regresi pada
 * CpeBindingService yang sudah lama stabil).
 *
 * DUA SUMBER resolusi, KEDUANYA wajib ter-resolve:
 * 1. `customers.ppp_package_id` — paket pelanggan SEKARANG (lewat
 *    `cpe_devices.customer`). Null kalau customer tidak ada atau belum
 *    punya paket -> gagal total.
 * 2. `cpe_devices.manufacturer` -> `modem_types.manufacturer_match_patterns`
 *    — logic TIDAK BERUBAH dari versi sebelumnya (null kalau tidak match
 *    sama sekali ATAU match ke LEBIH DARI SATU Tipe Modem/ambigu) -> gagal
 *    total kalau null.
 *
 * Begitu kedua sumber ter-resolve, template dicari dengan urutan:
 * exact match (ppp_package_id, modem_type_id) dulu, lalu fallback ke
 * template Default paket itu (modem_type_id NULL) kalau exact match tidak
 * ada. Null kalau TIDAK ADA satu pun dari keduanya (customer perlu
 * assignment manual).
 *
 * Constraint unique `(ppp_package_id, modem_type_id)` di DB menjamin
 * paling banyak SATU baris untuk tiap kombinasi — beda dari desain
 * modem-only kemarin (yang butuh cek "template AKTIF > 1 = ambigu"),
 * sekarang cukup `first()`, tidak ada ambiguitas struktural di level
 * Template lagi.
 */
class WanConfigTemplateSuggestionService
{
    public function suggestFor(CpeDevice $device): ?WanConfigTemplate
    {
        $modemTypeId = $this->resolveModemTypeId($device);

        if ($modemTypeId === null) {
            return null;
        }

        $pppPackageId = $device->loadMissing('customer')->customer?->ppp_package_id;

        if ($pppPackageId === null) {
            return null;
        }

        $exact = WanConfigTemplate::withoutGlobalScopes()
            ->where('tenant_id', $device->tenant_id)
            ->where('ppp_package_id', $pppPackageId)
            ->where('modem_type_id', $modemTypeId)
            ->first();

        if ($exact !== null) {
            return $exact;
        }

        return WanConfigTemplate::withoutGlobalScopes()
            ->where('tenant_id', $device->tenant_id)
            ->where('ppp_package_id', $pppPackageId)
            ->whereNull('modem_type_id')
            ->first();
    }

    /**
     * Null kalau manufacturer device kosong, tidak match Tipe Modem mana
     * pun, atau match LEBIH DARI SATU Tipe Modem sekaligus (ambigu, tidak
     * ada "yang benar" otomatis) — logic sama persis sebelumnya, tidak
     * diubah oleh revisi ini.
     */
    private function resolveModemTypeId(CpeDevice $device): ?int
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

        return $matchingModemTypes->first()->id;
    }

    /**
     * Normalisasi kode OUI GenieACS (`cpe_devices.manufacturer`) supaya
     * perbandingan tidak sensitif kapital/spasi. Sama normalisasi dengan
     * ModemType::matchPatterns().
     */
    public static function normalizeManufacturer(?string $manufacturer): ?string
    {
        $normalized = strtoupper(trim((string) $manufacturer));

        return $normalized === '' ? null : $normalized;
    }
}
