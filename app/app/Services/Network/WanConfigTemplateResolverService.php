<?php

namespace App\Services\Network;

use App\Models\CpeDevice;
use App\Models\WanConfigTemplate;

/**
 * v0.12.5 — resolve `WanConfigTemplate` yang berlaku untuk sebuah device,
 * ON-DEMAND. Dipanggil NANTI di v0.12.6 (push Konfig Remote), BUKAN
 * dipanggil/ditampilkan di Detail Perangkat CPE — halaman itu sekarang
 * menampilkan/meng-assign Tipe Modem device secara langsung (lihat
 * App\Services\Network\CpeModemTypeAssignmentService), tidak pernah
 * Template.
 *
 * `cpe_devices.modem_type_id` adalah SUMBER KEBENARAN di sini — method
 * ini TIDAK pernah re-matching OUI sendiri (itu tanggung jawab
 * ModemTypeSuggestionService, dipakai SEKALI saat mengisi
 * `modem_type_id` pertama kali). Keputusan ini (resolve Template
 * on-the-fly, TIDAK disimpan permanen di device) sengaja — Template yang
 * berubah/dihapus tidak butuh migrasi/update manual ke device manapun,
 * device cuma perlu tahu "saya pakai modem apa", bukan "template mana
 * yang berlaku untuk saya sekarang".
 *
 * Logic resolusi TIDAK BERUBAH dari WanConfigTemplateSuggestionService
 * (v0.12.5 sebelumnya): exact match (ppp_package_id, modem_type_id)
 * diutamakan, fallback ke template Default paket (modem_type_id NULL)
 * kalau exact match tidak ada. Null kalau customer tidak punya paket,
 * atau paket tidak punya template sama sekali (baik exact maupun
 * default).
 */
class WanConfigTemplateResolverService
{
    public function resolveTemplateForDevice(CpeDevice $device): ?WanConfigTemplate
    {
        $pppPackageId = $device->loadMissing('customer')->customer?->ppp_package_id;

        if ($pppPackageId === null) {
            return null;
        }

        $modemTypeId = $device->modem_type_id;

        if ($modemTypeId !== null) {
            $exact = WanConfigTemplate::withoutGlobalScopes()
                ->where('tenant_id', $device->tenant_id)
                ->where('ppp_package_id', $pppPackageId)
                ->where('modem_type_id', $modemTypeId)
                ->first();

            if ($exact !== null) {
                return $exact;
            }
        }

        return WanConfigTemplate::withoutGlobalScopes()
            ->where('tenant_id', $device->tenant_id)
            ->where('ppp_package_id', $pppPackageId)
            ->whereNull('modem_type_id')
            ->first();
    }
}
