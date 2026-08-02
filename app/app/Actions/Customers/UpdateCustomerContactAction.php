<?php

namespace App\Actions\Customers;

use App\Models\CustomerContact;
use Illuminate\Support\Facades\DB;

class UpdateCustomerContactAction
{
    public function handle(CustomerContact $contact, array $data): CustomerContact
    {
        return DB::transaction(function () use ($contact, $data) {
            if (! empty($data['is_authorized_contact'])) {
                CustomerContact::where('customer_id', $contact->customer_id)
                    ->where('id', '!=', $contact->id)
                    ->where('is_authorized_contact', true)
                    ->update(['is_authorized_contact' => false]);
            }

            $contact->update($data);

            return $contact->refresh();
        });
    }
}
