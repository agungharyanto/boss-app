<?php

namespace App\Actions\Customers;

use App\Models\Customer;

class UpdateCustomerAction
{
    /**
     * @param  array{name?: string, address?: string, phone_number?: string}  $data
     */
    public function handle(Customer $customer, array $data): Customer
    {
        $customer->update($data);

        return $customer->refresh();
    }
}
