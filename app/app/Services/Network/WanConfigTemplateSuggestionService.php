<?php

namespace App\Services\Network;

use App\Models\CpeDevice;
use App\Models\ModemType;
use App\Models\WanConfigTemplate;

/**
 * v0.12.5 — auto-suggest Template Konfig CPE untuk sebuah device,
 * BEST-EFFORT, TIDAK PERNAH garansi match. Dipanggil ON-DEMAND dari
 * Detail Perangkat CPE (bukan dari alur binding v0.7.5/CpeBindingService)
 * — lihat docblock ini sendiri untuk alasan keputusan arsitektur.
 *
 * KEPUTUSAN: on-demand di Detail Perangkat CPE, BUKAN dihook ke
 * CpeBindingService. Dua alasan:
 * 1. Freshness — katalog ModemType/Template bisa berubah kapan pun
 *    setelah device pertama kali di-bind; hook di binding-time akan
 *    "membekukan" hasil suggestion pada state katalog saat itu, tidak
 *    pernah ter-refresh otomatis kalau admin menambah/mengubah Tipe Modem
 *    belakangan. On-demand (dipanggil ulang tiap halaman dibuka) selalu
 *    mencerminkan katalog TERKINI.
 * 2. Risiko regresi — CpeBindingService (v0.7.1) adalah kode stabil lama
 *    yang dipanggil dari reconcile loop background TANPA konteks Auth
 *    (tenant harus di-pass manual, sama kelas masalah yang sudah
 *    ditangani WhatsappTemplateService::resolve()). Menyentuhnya untuk
 *    fitur baru ini menambah permukaan regresi pada alur binding yang
 *    sudah lama establish, di luar scope sub-versi ini.
 *
 * Query di sini SENGAJA `withoutGlobalScopes()` + `tenant_id` manual
 * (bukan bergantung pada TenantScope's Auth-based filter) — supaya tetap
 * benar regardless of caller context, sama disiplin
 * WhatsappTemplateService::resolve().
 */
class WanConfigTemplateSuggestionService
{
    /**
     * Null kalau: manufacturer device kosong, manufacturer tidak match
     * satu pun Tipe Modem, manufacturer match LEBIH DARI SATU Tipe Modem
     * (ambigu di level Tipe Modem), Tipe Modem yang match tidak punya
     * template aktif sama sekali, atau punya LEBIH DARI SATU template
     * aktif (ambigu di level Template — tidak ada "yang benar" otomatis,
     * serahkan ke manual, JANGAN asal pilih salah satu).
     */
    public function suggestFor(CpeDevice $device): ?WanConfigTemplate
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

        $modemType = $matchingModemTypes->first();

        $candidateTemplates = WanConfigTemplate::withoutGlobalScopes()
            ->where('tenant_id', $device->tenant_id)
            ->where('modem_type_id', $modemType->id)
            ->where('enabled', true)
            ->get();

        if ($candidateTemplates->count() !== 1) {
            return null;
        }

        return $candidateTemplates->first();
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
