<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reseller_id' => $this->reseller_id,
            'subscription_id' => $this->subscription_id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'technician_id' => $this->technician_id,
            'technician_name' => $this->whenLoaded('technician', fn () => $this->technician?->name),
            'odp_id' => $this->odp_id,
            'odp_name' => $this->whenLoaded('odp', fn () => $this->odp?->name),
            'odp_port_id' => $this->odp_port_id,
            'odp_port_number' => $this->whenLoaded('odpPort', fn () => $this->odpPort?->port_number),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'equipment_ready' => $this->equipment_ready,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'notes' => $this->notes,
            'devices' => $this->whenLoaded('devices', fn () => $this->devices->map(fn ($device) => [
                'id' => $device->id,
                'device_type' => $device->device_type->value,
                'mac_address' => $device->mac_address,
                'serial_number' => $device->serial_number,
                'scanned_at' => $device->scanned_at->toIso8601String(),
            ])),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($photo) => [
                'id' => $photo->id,
                'type' => $photo->type->value,
                'file_path' => $photo->file_path,
                'uploaded_at' => $photo->uploaded_at->toIso8601String(),
            ])),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
