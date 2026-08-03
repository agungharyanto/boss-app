<?php

namespace App\Services;

use App\Enums\ResellerStatus;
use App\Enums\ResellerUserRole;
use App\Enums\ResellerUserStatus;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ResellerService
{
    /**
     * status is set explicitly rather than relying on the migration's DB-level
     * default — Eloquent does not hydrate DB-computed defaults back onto the
     * in-memory model after an insert (same gap documented on
     * App\Actions\Customers\CreateCustomerAction), and ResellerResource reads
     * ->status->value on the instance returned from here immediately after.
     *
     * @param  array{name: string, slug?: ?string, email?: ?string, phone?: ?string, address?: ?string, notes?: ?string}  $data
     */
    public function createReseller(array $data): Reseller
    {
        return Reseller::create([...$data, 'status' => ResellerStatus::Active]);
    }

    /**
     * @param  array{name?: string, slug?: ?string, email?: ?string, phone?: ?string, address?: ?string, notes?: ?string}  $data
     */
    public function updateReseller(Reseller $reseller, array $data): Reseller
    {
        $reseller->update($data);

        return $reseller->refresh();
    }

    public function suspendReseller(Reseller $reseller): Reseller
    {
        $reseller->update(['status' => ResellerStatus::Suspended]);

        return $reseller->refresh();
    }

    /**
     * Attaches $user to $reseller, or reactivates/re-roles them if they were
     * already attached before (syncWithoutDetaching updates pivot columns on
     * an already-present id rather than erroring on the unique constraint).
     */
    public function attachUser(Reseller $reseller, User $user, ResellerUserRole $role): void
    {
        $reseller->users()->syncWithoutDetaching([
            $user->id => [
                'role' => $role->value,
                'status' => ResellerUserStatus::Active->value,
            ],
        ]);
    }

    /**
     * Soft-detaches $user: marks their reseller_users row 'inactive' rather
     * than deleting it, preserving the membership history (the status column
     * exists specifically for this — a hard pivot delete would make it dead
     * weight). ResellerScope/CustomerPolicy/ResellerPackagePricingPolicy all
     * already filter on status=active, so an inactive membership stops
     * granting access immediately.
     */
    public function detachUser(Reseller $reseller, User $user): void
    {
        $reseller->users()->updateExistingPivot($user->id, [
            'status' => ResellerUserStatus::Inactive->value,
        ]);
    }

    public function listUsers(Reseller $reseller): Collection
    {
        return $reseller->users()->get();
    }
}
