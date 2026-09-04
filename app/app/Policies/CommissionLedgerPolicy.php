<?php

namespace App\Policies;

use App\Models\User;

/**
 * v0.9.6 — read-only, tier-admin-only. Bentuk sama dengan
 * CommissionRatePolicy: `commission_ledger` tenant-level (tidak punya
 * reseller_id), isolasi lintas-tenant ditangani global scope
 * BelongsToTenant milik CommissionLedger sendiri. Tidak ada `manage`
 * di v0.9.6 — belum ada aksi tulis admin (Titip auto-Eligible saat submit
 * Referrer). v0.9.7 akan menambah aksi + permission-nya sendiri.
 */
class CommissionLedgerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('commission_ledger.view');
    }

    public function view(User $user): bool
    {
        return $user->can('commission_ledger.view');
    }
}
