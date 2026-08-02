<?php

namespace App\Actions\Customers;

use App\Enums\CustomerStatus;
use App\Models\Customer;

class CreateCustomerAction
{
    /**
     * Every new customer starts as 'prospek' — set explicitly here rather
     * than relying on the migration's DB-level default, since Eloquent
     * does not hydrate DB-computed defaults back onto the in-memory model
     * after an insert (the CustomerObserver's "created" listener needs the
     * enum populated on the same instance it just received).
     *
     * @param  array{name: string, address: string, phone_number: string}  $data
     */
    public function handle(array $data): Customer
    {
        return Customer::create([...$data, 'status' => CustomerStatus::Prospek]);
    }
}
