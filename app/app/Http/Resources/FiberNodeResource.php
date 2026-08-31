<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiberNodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'reseller_id' => $this->reseller_id,
            'node_type' => $this->node_type->value,
            'node_type_label' => $this->node_type->label(),
            'local_label' => $this->local_label,
            'parent_type' => $this->parent_type,
            'parent_id' => $this->parent_id,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'loss_in_db' => $this->loss_in_db !== null ? (float) $this->loss_in_db : null,
            'loss_out_db' => $this->loss_out_db !== null ? (float) $this->loss_out_db : null,
            'notes' => $this->notes,
            'photos' => FiberNodePhotoResource::collection($this->whenLoaded('photos')),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
