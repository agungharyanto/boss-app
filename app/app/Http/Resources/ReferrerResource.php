<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferrerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'is_active' => $this->is_active,
            // Never a raw generated_password here — that's shown exactly
            // once via a separate response field at create/generate time
            // (see ReferrerController), never re-derivable/re-shown from a
            // resource read.
            'has_login_account' => $this->user_id !== null,
            'user_id' => $this->user_id,
            'user_email' => $this->whenLoaded('user', fn () => $this->user?->email),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
