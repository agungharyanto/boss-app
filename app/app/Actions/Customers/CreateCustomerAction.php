<?php

namespace App\Actions\Customers;

use App\Models\Customer;

class CreateCustomerAction
{
    /**
     * @param  array{name: string, address: string, phone_number: string}  $data
     */
    public function handle(array $data): Customer
    {
        return Customer::create($data);
    }
}
