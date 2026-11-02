<?php

namespace App\Policies;

use App\Models\User;

/**
 * v0.19.1 — tier-admin-only (superadmin + administrator), sama posture
 * PaymentGatewaySettingsPolicy. Permission yang sama (company_profile.*)
 * juga dipakai halaman editor Syarat & Ketentuan Pemasangan (lihat
 * InstallationTermsSettings — ia cek permission string langsung, bukan
 * policy, karena InstallationTerms tidak punya satu baris singleton untuk
 * di-authorize). Tidak ada instance model (singleton per tenant, tanpa
 * route-model binding) — bentuk class-level-only sama dengan
 * PaymentGatewaySettingsPolicy.
 */
class CompanyProfilePolicy
{
    public function view(User $user): bool
    {
        return $user->can('company_profile.view');
    }

    public function manage(User $user): bool
    {
        return $user->can('company_profile.manage');
    }
}
