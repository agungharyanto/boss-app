<?php

namespace App\Policies;

use App\Models\User;

/**
 * v0.12.5 — "Template Konfig CPE" (matrix Paket x Tipe Modem). Reuse
 * permission `remote_config.*` yang sudah ada (bukan permission baru) —
 * fitur ini adalah bagian dari domain GenieACS Auto-WAN config yang sama
 * dengan "Konfig Remote" (RemoteWanConfig, singleton lama, TIDAK dihapus,
 * dibangun paralel), jadi sengaja berbagi permission yang sama. Sama
 * posture RemoteWanConfigPolicy: tier-admin + noc (provisioning jaringan
 * = tugas operasional NOC).
 *
 * Class-level (tanpa route-model binding) untuk viewAny/manage, sama
 * pola NetworkProfileGroupPolicy — juga dipakai untuk otorisasi
 * `ModemType` (CRUD inline di halaman yang sama, bukan modul terpisah).
 */
class WanConfigTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('remote_config.view') || $user->can('remote_config.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('remote_config.view') || $user->can('remote_config.manage');
    }

    public function manage(User $user): bool
    {
        return $user->can('remote_config.manage');
    }
}
