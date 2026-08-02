<?php

namespace App\Policies;

use App\Models\CustomerContact;
use App\Models\User;

class CustomerContactPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('customers.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, CustomerContact $customerContact): bool
    {
        return $user->can('customers.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('customer_contacts.manage');
    }

    /**
     * Determine whether the user can update the model, including toggling
     * it as the authorized contact.
     */
    public function update(User $user, CustomerContact $customerContact): bool
    {
        return $user->can('customer_contacts.manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CustomerContact $customerContact): bool
    {
        return $user->can('customer_contacts.manage');
    }
}
