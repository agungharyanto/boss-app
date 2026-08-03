<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the array shape returned by ThemeSettingsService::get(), not an Eloquent model.
 */
class ThemeSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'primary_color' => $this->resource['primary_color'],
            'text_color' => $this->resource['text_color'],
        ];
    }
}
