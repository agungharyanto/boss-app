<?php

namespace App\Policies;

use App\Models\User;

/**
 * v0.22.1 — CRUD Staff (Manajemen User). Tier-admin-only (superadmin/
 * administrator), sesuai spesifikasi eksplisit: 4 method terpisah
 * (viewAny/view/create/update), BUKAN pola collapse `manage()` yang
 * dipakai BandwidthProfilePolicy dkk — penyimpangan disengaja, bukan
 * kelalaian meniru pola existing.
 *
 * `User` model TIDAK pakai `BelongsToTenant` (lihat CLAUDE.md — "User
 * itself deliberately does NOT use this trait"), jadi TIDAK ADA
 * TenantScope otomatis yang membatasi query lintas tenant. `view()`/
 * `update()` di bawah SENGAJA membandingkan `$user->tenant_id ===
 * $model->tenant_id` secara eksplisit sebagai jaring pengaman —
 * konsisten dengan keputusan lama yang sudah dikunci: "super_admin is
 * tenant-scoped, not a cross-tenant platform role... every user,
 * including super_admin, has a required tenant_id." Tanpa guard ini,
 * seorang admin tenant A bisa melihat/mengubah staff tenant B lewat ID
 * tebakan — bukan sekadar teoretis, `User::find()` polos memang tidak
 * terfilter apa pun di level query.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view') || $user->can('users.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $user->tenant_id === $model->tenant_id
            && ($user->can('users.view') || $user->can('users.manage'));
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $user->tenant_id === $model->tenant_id && $user->can('users.manage');
    }

    /**
     * v0.22.1 (revisi) — Delete permanen. Sama posture dengan `update()`
     * (tier-admin + guard tenant_id eksplisit) — cek relasi nyata yang bisa
     * menahan delete (reseller_users/technicians/cpe_action_logs) ada di
     * `StaffService::delete()`, bukan di sini; Policy ini murni "siapa
     * boleh MENCOBA menghapus", bukan "apakah delete-nya akan berhasil".
     */
    public function delete(User $user, User $model): bool
    {
        return $user->tenant_id === $model->tenant_id && $user->can('users.manage');
    }
}
