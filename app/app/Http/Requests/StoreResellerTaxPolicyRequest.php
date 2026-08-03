<?php

namespace App\Http\Requests;

use App\Models\Reseller;
use App\Models\ResellerTaxPolicy;
use App\Models\TaxComponent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreResellerTaxPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reseller = $this->filled('reseller_id') ? Reseller::find($this->input('reseller_id')) : null;

        return $this->user()->can('create', [ResellerTaxPolicy::class, $reseller]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Null = direct-retail policy.
            'reseller_id' => [
                'nullable',
                Rule::exists(Reseller::class, 'id')->where('tenant_id', $this->user()->tenant_id),
            ],
            'tax_component_id' => [
                'required',
                Rule::exists(TaxComponent::class, 'id')->where('tenant_id', $this->user()->tenant_id),
            ],
            'burden' => ['required', 'in:customer_borne,reseller_borne,split'],
            'split_ratio' => ['required_if:burden,split', 'nullable', 'numeric', 'between:0,100'],
            'effective_from' => ['required', 'date'],
        ];
    }
}
