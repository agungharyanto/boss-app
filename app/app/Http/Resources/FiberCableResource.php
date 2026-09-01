<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiberCableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'from_type' => $this->from_type,
            'from_id' => $this->from_id,
            'to_type' => $this->to_type,
            'to_id' => $this->to_id,
            'total_cores' => $this->total_cores,
            'tube_count' => $this->tube_count,
            'cores_per_tube' => $this->cores_per_tube,
            'cores_count' => $this->whenCounted('cores'),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
