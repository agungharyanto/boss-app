<?php

namespace App\Actions\Customers;

use App\Enums\CustomerStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Customer;

class UpdateCustomerStatusAction
{
    /**
     * Re-checks the transition here (on top of the Form Request validation)
     * as a safety net against a status change racing this request.
     */
    public function handle(Customer $customer, CustomerStatus $status): Customer
    {
        if (! $customer->status->canTransitionTo($status)) {
            throw new InvalidStatusTransitionException($customer->status, $status);
        }

        $customer->update(['status' => $status]);

        return $customer->refresh();
    }
}
