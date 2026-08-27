<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BandwidthProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'upload_min' => $this->upload_min,
            'upload_max' => $this->upload_max,
            'download_min' => $this->download_min,
            'download_max' => $this->download_max,
            'upload_min_display' => $this->upload_min_display,
            'upload_max_display' => $this->upload_max_display,
            'download_min_display' => $this->download_min_display,
            'download_max_display' => $this->download_max_display,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
