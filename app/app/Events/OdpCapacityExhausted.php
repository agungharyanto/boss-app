<?php

namespace App\Events;

use App\Models\Customer;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 4. Dispatched
 * from OdpLocatorService::findNearestAvailable() ONLY when it returns
 * null because every available ODP port in scope is genuinely exhausted
 * (zero rows matched the `status = available` query) — never when it
 * returns null merely because the customer has no GPS coordinates. Kept
 * as a separate event (not a change to findNearestAvailable()'s own
 * signature/return type) specifically so every existing caller
 * (registration flow, WorkOrderService) is completely unaffected — see
 * that method's own docblock for the exact split.
 */
class OdpCapacityExhausted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Customer $customer,
    ) {}
}
