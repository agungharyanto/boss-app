<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiberAccessoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fiber_cable_id' => $this->fiber_cable_id,
            'splitter_id' => $this->splitter_id,
            'accessory_type' => $this->accessory_type->value,
            'accessory_type_label' => $this->accessory_type->label(),
            'expected_loss_db' => $this->expected_loss_db !== null ? (float) $this->expected_loss_db : null,
            'measured_loss_db' => $this->measured_loss_db !== null ? (float) $this->measured_loss_db : null,
            'location_note' => $this->location_note,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
