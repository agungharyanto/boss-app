<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Support\Facades\DB;

class CreateCustomerContactAction
{
    /**
     * Marking a new contact as authorized automatically un-marks whichever
     * contact previously held that slot, keeping the "exactly one
     * authorized contact per customer" invariant without a clunky two-step
     * API flow (the DB partial unique index remains as the hard backstop).
     */
    public function handle(Customer $customer, array $data): CustomerContact
    {
        return DB::transaction(function () use ($customer, $data) {
            if (! empty($data['is_authorized_contact'])) {
                $customer->contacts()
                    ->where('is_authorized_contact', true)
                    ->update(['is_authorized_contact' => false]);
            }

            return $customer->contacts()->create($data);
        });
    }
}
