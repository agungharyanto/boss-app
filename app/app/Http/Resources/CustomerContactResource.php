<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerContactResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'name' => $this->name,
            'phone_number' => $this->phone_number,
            'relationship' => $this->relationship,
            'access_level' => $this->access_level->value,
            'access_level_label' => $this->access_level->label(),
            'can_view_billing' => $this->can_view_billing,
            'can_request_service_change' => $this->can_request_service_change,
            'can_receive_notifications' => $this->can_receive_notifications,
            'is_authorized_contact' => $this->is_authorized_contact,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
