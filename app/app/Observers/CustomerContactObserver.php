<?php

namespace App\Observers;

use App\Models\CustomerContact;
use App\Models\CustomerTimelineEntry;
use Illuminate\Support\Facades\Auth;

class CustomerContactObserver
{
    private const TRACKED_FIELDS = [
        'name',
        'phone_number',
        'relationship',
        'access_level',
        'can_view_billing',
        'can_request_service_change',
        'can_receive_notifications',
        'is_authorized_contact',
    ];

    /**
     * Handle the CustomerContact "created" event.
     */
    public function created(CustomerContact $customerContact): void
    {
        CustomerTimelineEntry::create([
            'tenant_id' => $customerContact->tenant_id,
            'customer_id' => $customerContact->customer_id,
            'event_type' => 'contact_created',
            'description' => "Kontak {$customerContact->name} ditambahkan ({$customerContact->access_level->label()})",
            'changes' => $this->snapshot($customerContact),
            'actor_id' => Auth::id(),
        ]);
    }

    /**
     * Handle the CustomerContact "updated" event.
     */
    public function updated(CustomerContact $customerContact): void
    {
        $changedFields = array_intersect(array_keys($customerContact->getChanges()), self::TRACKED_FIELDS);

        if ($changedFields === []) {
            return;
        }

        $changes = [];
        foreach ($changedFields as $field) {
            $changes[$field] = [
                'from' => $customerContact->getOriginal($field),
                'to' => $customerContact->getAttribute($field),
            ];
        }

        CustomerTimelineEntry::create([
            'tenant_id' => $customerContact->tenant_id,
            'customer_id' => $customerContact->customer_id,
            'event_type' => 'contact_updated',
            'description' => "Kontak {$customerContact->name} diperbarui: ".implode(', ', $changedFields),
            'changes' => $changes,
            'actor_id' => Auth::id(),
        ]);
    }

    /**
     * Handle the CustomerContact "deleted" event.
     */
    public function deleted(CustomerContact $customerContact): void
    {
        CustomerTimelineEntry::create([
            'tenant_id' => $customerContact->tenant_id,
            'customer_id' => $customerContact->customer_id,
            'event_type' => 'contact_deleted',
            'description' => "Kontak {$customerContact->name} dihapus",
            'changes' => $this->snapshot($customerContact),
            'actor_id' => Auth::id(),
        ]);
    }

    private function snapshot(CustomerContact $customerContact): array
    {
        return [
            'name' => $customerContact->name,
            'phone_number' => $customerContact->phone_number,
            'relationship' => $customerContact->relationship,
            'access_level' => $customerContact->access_level->value,
            'is_authorized_contact' => $customerContact->is_authorized_contact,
        ];
    }
}
