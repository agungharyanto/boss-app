<?php

namespace App\Actions\Customers;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Support\ResellerContext;

class CreateCustomerAction
{
    public function __construct(private readonly ResellerContext $resellerContext) {}

    /**
     * Every new customer starts as 'prospek' — set explicitly here rather
     * than relying on the migration's DB-level default, since Eloquent
     * does not hydrate DB-computed defaults back onto the in-memory model
     * after an insert (the CustomerObserver's "created" listener needs the
     * enum populated on the same instance it just received).
     *
     * reseller_id attribution (v0.3.2) mirrors RegistrationService's referrer
     * attribution rule: a caller operating under a resolved reseller context
     * (reseller owner/staff — see ResolveResellerContext) is always
     * attributed to that reseller, ignoring any reseller_id passed in $data.
     * With no active reseller context, an explicit reseller_id in $data is
     * honored as-is (an ISP admin directly assigning a customer to a
     * reseller); otherwise the customer is a direct ISP customer
     * (reseller_id stays null).
     *
     * @param  array{name: string, address: string, phone_number: string, reseller_id?: ?int}  $data
     */
    public function handle(array $data): Customer
    {
        $data['reseller_id'] = $this->resellerContext->reseller()?->id ?? $data['reseller_id'] ?? null;

        return Customer::create([...$data, 'status' => CustomerStatus::Prospek]);
    }
}
