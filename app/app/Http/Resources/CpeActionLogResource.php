<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CpeActionLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action_type' => $this->action_type->value,
            'action_type_label' => $this->action_type->label(),
            'parameters' => $this->parameters,
            'genieacs_task_id' => $this->genieacs_task_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'failed_reason' => $this->failed_reason,
            'performed_by_name' => $this->whenLoaded('performedBy', fn () => $this->performedBy?->name),
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
