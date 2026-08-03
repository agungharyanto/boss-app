<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Update" here means: set a new effective-dated policy for the same
 * reseller+tax_component pair as the route's {reseller_tax_policy} —
 * App\Services\Tax\ResellerTaxPolicyService::setPolicy() always inserts a
 * new row and closes out whatever was previously open-ended, it never
 * mutates an existing row's burden/split_ratio in place (same effective-
 * dating principle as tax_components rate changes).
 */
class UpdateResellerTaxPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('reseller_tax_policy'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'burden' => ['required', 'in:customer_borne,reseller_borne,split'],
            'split_ratio' => ['required_if:burden,split', 'nullable', 'numeric', 'between:0,100'],
            'effective_from' => ['required', 'date'],
        ];
    }
}
