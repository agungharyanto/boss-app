<?php

namespace App\Http\Requests;

use App\Models\TaxComponent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaxComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', TaxComponent::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique(TaxComponent::class, 'code')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->where('effective_from', $this->input('effective_from')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:percentage,fixed'],
            'rate' => ['required', 'numeric', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
