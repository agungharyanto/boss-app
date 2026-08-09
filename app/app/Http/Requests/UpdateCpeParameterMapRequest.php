<?php

namespace App\Http\Requests;

use App\Enums\CpeParameterConversionFormula;
use App\Models\CpeParameterMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Deliberately does NOT accept `verified_at`/`verified_against_device_id` —
 * editing the path/formula/params of an already-verified row should demote
 * it back to unverified rather than silently keep an old verification
 * timestamp attached to now-different data. Re-verifying goes through
 * CpeParameterMapController::markVerified() instead, a separate explicit
 * action, never a side effect of a generic field edit.
 */
class UpdateCpeParameterMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', CpeParameterMap::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parameter_path' => ['sometimes', 'required', 'string', 'max:1000'],
            'value_type' => ['nullable', 'string', 'max:64'],
            'conversion_formula' => ['sometimes', 'required', new Enum(CpeParameterConversionFormula::class)],
            'conversion_params' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
