<?php

namespace App\Policies;

use App\Enums\ResellerUserStatus;
use App\Models\ResellerUser;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkOrder;

/**
 * Same shape as OdpPolicy/TechnicianPolicy: admin (work_orders.view/.manage)
 * gets full access including direct-customer work orders; a reseller's own
 * work order (reseller_id not null) is viewable/manageable by any active
 * reseller_users membership. "manage" covers every state-transition action
 * (verify/assign/start/complete/cancel) — WorkOrderService itself enforces
 * which transitions are legal, this Policy only gates who may attempt one
 * at all.
 */
class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('work_orders.view') || $user->can('work_orders.manage') || $this->belongsToAnyReseller($user);
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        if ($user->can('work_orders.view') || $user->can('work_orders.manage')) {
            return true;
        }

        return $workOrder->reseller_id !== null && $this->belongsToReseller($user, $workOrder->reseller_id);
    }

    public function manage(User $user, WorkOrder $workOrder): bool
    {
        if ($user->can('work_orders.manage')) {
            return true;
        }

        return $workOrder->reseller_id !== null && $this->belongsToReseller($user, $workOrder->reseller_id);
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
