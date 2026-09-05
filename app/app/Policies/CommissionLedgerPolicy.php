<?php

namespace App\Policies;

use App\Models\User;

/**
 * v0.9.6 — read-only, tier-admin-only. Bentuk sama dengan
 * CommissionRatePolicy: `commission_ledger` tenant-level (tidak punya
 * reseller_id), isolasi lintas-tenant ditangani global scope
 * BelongsToTenant milik CommissionLedger sendiri.
 *
 * Sprint "perpanjang-daftar-pelanggan" (tracking setoran) — menambah
 * `markDeposit` (`commission_ledger.manage`): admin menandai setoran uang
 * titip Referrer sudah masuk. Masih tidak ada approve/reject — nominal
 * komisi terkunci ke rate, OTP WhatsApp ke Referrer jadi jaring pengaman.
 *
 * v0.9.11 (Payout Komisi) — `markPaid` reuse permission `.manage` yang
 * sama (sama posture `markDeposit`, tidak ada permission baru) untuk
 * menandai baris komisi (Titip instan ATAU batch bulanan) sebagai Paid.
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

    public function markDeposit(User $user): bool
    {
        return $user->can('commission_ledger.manage');
    }

    public function markPaid(User $user): bool
    {
        return $user->can('commission_ledger.manage');
    }
}
