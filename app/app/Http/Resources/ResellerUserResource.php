<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResellerUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->pivot->role->value,
            'role_label' => $this->pivot->role->label(),
            'status' => $this->pivot->status->value,
            'status_label' => $this->pivot->status->label(),
        ];
    }
}
