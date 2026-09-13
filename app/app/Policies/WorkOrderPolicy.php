<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Enums\WorkOrderStatus;
use App\Models\ResellerUser;
use App\Models\Subscription;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Same shape as OdpPolicy/TechnicianPolicy: admin (work_orders.view/.manage)
 * gets full access including direct-customer work orders; a reseller's own
 * work order (reseller_id not null) is viewable/manageable by any active
 * reseller_users membership. "manage" covers every state-transition action
 * (verify/assign/start/complete/cancel) — WorkOrderService itself enforces
 * which transitions are legal, this Policy only gates who may attempt one
 * at all.
 *
 * v0.12.3 — Technician-scoped API (`work_orders.technician` permission).
 * TWO INDEPENDENT paths, deliberately never synced with each other
 * (keputusan Agung eksplisit, "Opsi C"):
 *   1. `workOrder.technician_id === $user`'s own Technician row —
 *      assignment resmi admin (WorkOrderService::assignTechnician()),
 *      dicek REAL-TIME dari kondisi TERKINI kolom itu. Re-assign ke
 *      teknisi lain otomatis mencabut akses teknisi lama TANPA perlu
 *      membersihkan apa pun — tidak ada state tersimpan yang bisa basi.
 *   2. Klaim mandiri di `work_order_technicians` (lihat
 *      WorkOrderController::claim()) — independen sepenuhnya dari #1,
 *      TIDAK PERNAH ditulis/dihapus oleh assignTechnician().
 * WO yang masih unclaimed (technician_id null DAN belum ada satu pun
 * klaim) DAN belum completed/cancelled tetap terlihat SEMUA pemegang
 * `work_orders.technician` — supaya bisa di-browse sebelum diklaim.
 * Begitu WO punya assignment ATAU klaim (siapa pun), WO itu TIDAK LAGI
 * "unclaimed" — teknisi lain yang bukan bagian dari salah satu jalur di
 * atas tidak lagi melihatnya.
 */
class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('work_orders.view')
            || $user->can('work_orders.manage')
            || $this->belongsToAnyReseller($user)
            || $user->can('work_orders.technician');
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        if ($user->can('work_orders.view') || $user->can('work_orders.manage')) {
            return true;
        }

        if ($workOrder->reseller_id !== null && $this->belongsToReseller($user, $workOrder->reseller_id)) {
            return true;
        }

        if ($user->can('work_orders.technician')) {
            return $this->isAssignedOrClaimedByTechnician($user, $workOrder) || $this->isUnclaimed($workOrder);
        }

        return false;
    }

    /**
     * TIDAK termasuk cabang "unclaimed" — melihat sebuah WO yang belum
     * diklaim siapa pun (untuk browsing) BUKAN alasan sah untuk
     * verify/assign/start/complete/cancel-nya. Teknisi harus klaim
     * (atau di-assign admin) dulu sebelum boleh mengelola state WO itu.
     */
    public function manage(User $user, WorkOrder $workOrder): bool
    {
        if ($user->can('work_orders.manage')) {
            return true;
        }

        if ($workOrder->reseller_id !== null && $this->belongsToReseller($user, $workOrder->reseller_id)) {
            return true;
        }

        if ($user->can('work_orders.technician')) {
            return $this->isAssignedOrClaimedByTechnician($user, $workOrder);
        }

        return false;
    }

    /**
     * A new work order only ever makes sense scoped to the subscription
     * it's for — a reseller may create one only for their own
     * subscriptions, never on a different reseller's or a direct-retail one.
     */
    public function create(User $user, Subscription $subscription): bool
    {
        if ($user->can('work_orders.manage')) {
            return true;
        }

        return $subscription->reseller_id !== null && $this->belongsToReseller($user, $subscription->reseller_id);
    }

    /**
     * True kalau `$user` TIDAK punya akses admin-wide (`.view`/`.manage`)
     * maupun lewat keanggotaan reseller — HANYA lewat `work_orders.technician`.
     * Dipakai `WorkOrderController::index()` untuk memutuskan apakah query
     * daftar perlu di-scope ke `scopeForTechnician()` di bawah.
     */
    public function isTechnicianOnly(User $user): bool
    {
        return ! $user->can('work_orders.view')
            && ! $user->can('work_orders.manage')
            && ! $this->belongsToAnyReseller($user)
            && $user->can('work_orders.technician');
    }

    /**
     * Query-level padanan `view()`'s technician branch — dipakai
     * `WorkOrderController::index()` supaya daftar yang tampil untuk
     * teknisi murni konsisten dengan apa yang boleh dia buka satu-satu
     * (bukan dua definisi visibility yang bisa drift).
     */
    public function scopeForTechnician(User $user, Builder $query): Builder
    {
        $technicianId = Technician::query()->where('user_id', $user->id)->value('id');

        return $query->where(function (Builder $q) use ($technicianId) {
            $q->where('technician_id', $technicianId)
                ->orWhereHas('claimedByTechnicians', fn (Builder $c) => $c->where('technicians.id', $technicianId))
                ->orWhere(function (Builder $unclaimed) {
                    $unclaimed->whereNull('technician_id')
                        ->whereDoesntHave('claimedByTechnicians')
                        ->whereNotIn('status', [WorkOrderStatus::Completed->value, WorkOrderStatus::Cancelled->value]);
                });
        });
    }

    private function isAssignedOrClaimedByTechnician(User $user, WorkOrder $workOrder): bool
    {
        $technician = Technician::query()->where('user_id', $user->id)->first();

        if ($technician === null) {
            return false;
        }

        if ($workOrder->technician_id === $technician->id) {
            return true;
        }

        return $workOrder->claimedByTechnicians()->where('technicians.id', $technician->id)->exists();
    }

    private function isUnclaimed(WorkOrder $workOrder): bool
    {
        if (in_array($workOrder->status, [WorkOrderStatus::Completed, WorkOrderStatus::Cancelled], true)) {
            return false;
        }

        return $workOrder->technician_id === null && ! $workOrder->claimedByTechnicians()->exists();
    }

    private function belongsToReseller(User $user, int $resellerId): bool
    {
        return ResellerUser::query()
            ->where('reseller_id', $resellerId)
            ->where('user_id', $user->id)
            ->where('status', ResellerUserStatus::Active)
            ->exists();
    }

    private function belongsToAnyReseller(User $user): bool
    {
        return ResellerUser::query()
            ->where('user_id', $user->id)
            ->where('status', ResellerUserStatus::Active)
            ->exists();
    }
}
