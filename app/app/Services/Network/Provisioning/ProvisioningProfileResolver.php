<?php

namespace App\Services\Network\Provisioning;

use App\Models\CpeDevice;
use App\Models\PppPackage;
use App\Models\ProvisioningProfile;

/**
 * Resolusi profil ZTP per CPE (v0.23.8.3) — ON-DEMAND, tidak disimpan di
 * device (pola WanConfigTemplateResolverService). Urutan prioritas (section
 * D6 docs/omci/ztp-dynamic-config-design.md):
 *   1. Override CPE eksplisit (cpe_devices.provisioning_profile_id) — MENANG.
 *   2. Profil paket ber-is_default=true.
 *   3. Kalau paket punya TEPAT 1 profil → itu.
 *   4. Kalau >1 tanpa default → AMBIGU: null + alasan (UI minta pilih eksplisit).
 *
 * withoutGlobalScopes() dipakai di semua lookup supaya deterministik di konteks
 * job tanpa Auth (aktivasi bisa jalan dari queue) — konsistensi tenant dijamin
 * oleh konstruksi data (profil paket & override selalu se-tenant dgn CPE-nya).
 */
class ProvisioningProfileResolver
{
    public function resolveForCpe(CpeDevice $cpe): ProfileResolutionResult
    {
        // 1. Override per-CPE. (Branch 'override_missing' defensif saja — FK +
        // nullOnDelete menjamin override tak pernah dangling; hapus profil -> NULL.)
        if ($cpe->provisioning_profile_id !== null) {
            $override = ProvisioningProfile::withoutGlobalScopes()->find($cpe->provisioning_profile_id);

            return $override !== null
                ? new ProfileResolutionResult($override, 'override_cpe')
                : new ProfileResolutionResult(null, 'override_missing',
                    "Override profil CPE menunjuk profil #{$cpe->provisioning_profile_id} yang tidak ada.");
        }

        // 2. Paket pelanggan.
        $customer = $cpe->loadMissing('customer')->customer;
        if ($customer === null) {
            return new ProfileResolutionResult(null, 'no_customer', 'CpeDevice tidak punya customer tertaut.');
        }

        $packageId = $customer->ppp_package_id;
        if ($packageId === null) {
            return new ProfileResolutionResult(null, 'no_package', 'Pelanggan belum punya paket (ppp_package_id).');
        }

        $package = PppPackage::withoutGlobalScopes()->find($packageId);
        if ($package === null) {
            return new ProfileResolutionResult(null, 'no_package', "Paket #{$packageId} tidak ditemukan.");
        }

        $profiles = $package->provisioningProfiles()->withoutGlobalScopes()->get();

        // 3. Default paket.
        $default = $profiles->first(fn (ProvisioningProfile $p): bool => (bool) $p->pivot->is_default);
        if ($default !== null) {
            return new ProfileResolutionResult($default, 'package_default');
        }

        // 4. Tunggal / tak ada / ambigu.
        if ($profiles->count() === 1) {
            return new ProfileResolutionResult($profiles->first(), 'package_single');
        }

        if ($profiles->isEmpty()) {
            return new ProfileResolutionResult(null, 'no_profile',
                "Paket #{$packageId} belum punya profil ZTP apa pun.");
        }

        return new ProfileResolutionResult(null, 'ambiguous',
            "Paket #{$packageId} punya {$profiles->count()} profil tanpa default — pilih profil eksplisit saat aktivasi.");
    }
}
