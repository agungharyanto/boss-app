<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the array shape {locale, supported} built in the controller, not an Eloquent model.
 */
class LocaleSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'locale' => $this->resource['locale'],
            'supported' => $this->resource['supported'],
        ];
    }
}
