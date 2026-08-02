<?php

namespace App\Observers;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerTimelineEntry;
use Illuminate\Support\Facades\Auth;

class CustomerObserver
{
    private const TRACKED_PROFILE_FIELDS = ['name', 'address', 'phone_number'];

    /**
     * Handle the Customer "created" event.
     */
    public function created(Customer $customer): void
    {
        CustomerTimelineEntry::create([
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'event_type' => 'customer_created',
            'description' => "Pelanggan baru dibuat dengan status {$customer->status->label()}",
            'changes' => [
                'name' => $customer->name,
                'address' => $customer->address,
                'phone_number' => $customer->phone_number,
                'status' => $customer->status->value,
            ],
            'actor_id' => Auth::id(),
        ]);
    }

    /**
     * Handle the Customer "updated" event.
     */
    public function updated(Customer $customer): void
    {
        if ($customer->wasChanged('status')) {
            $from = $this->statusFrom($customer->getOriginal('status'));
            $to = $customer->status;

            CustomerTimelineEntry::create([
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->id,
                'event_type' => 'status_changed',
                'description' => "Status diubah dari {$from->label()} ke {$to->label()}",
                'changes' => ['from' => $from->value, 'to' => $to->value],
                'actor_id' => Auth::id(),
            ]);
        }

        $changedProfileFields = array_intersect(array_keys($customer->getChanges()), self::TRACKED_PROFILE_FIELDS);

        if ($changedProfileFields !== []) {
            $changes = [];
            foreach ($changedProfileFields as $field) {
                $changes[$field] = [
                    'from' => $customer->getOriginal($field),
                    'to' => $customer->getAttribute($field),
                ];
            }

            CustomerTimelineEntry::create([
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->id,
                'event_type' => 'profile_updated',
                'description' => 'Profil pelanggan diperbarui: '.implode(', ', $changedProfileFields),
                'changes' => $changes,
                'actor_id' => Auth::id(),
            ]);
        }
    }

    private function statusFrom(CustomerStatus|string $status): CustomerStatus
    {
        return $status instanceof CustomerStatus ? $status : CustomerStatus::from($status);
    }
}
