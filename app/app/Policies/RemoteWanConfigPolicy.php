<?php

namespace App\Policies;

use App\Models\User;

/**
 * "Konfig Remote" (GenieACS Auto-WAN). Tier-admin + noc — sama posture
 * `monitoring.*` (NOC mengelola infra jaringan ISP, dan Auto-WAN provisioning
 * adalah keputusan operasional jaringan, bukan admin-tinggi seperti kredensial
 * Payment Gateway). Singleton, tanpa route-model binding — class-level saja.
 */
class RemoteWanConfigPolicy
{
    public function view(User $user): bool
    {
        return $user->can('remote_config.view');
    }

    public function manage(User $user): bool
    {
        return $user->can('remote_config.manage');
    }
}
