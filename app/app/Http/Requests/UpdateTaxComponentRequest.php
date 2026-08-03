<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Generic field update only — name/description/is_active. `rate` is
 * deliberately excluded here: it can only change via the dedicated
 * update-rate endpoint (App\Services\Tax\TaxComponentService::updateRate),
 * which effective-dates the change instead of mutating history in place.
 */
class UpdateTaxComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('tax_component'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
