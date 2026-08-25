<?php

namespace App\Http\Requests;

use App\Models\Reseller;
use App\Models\ResellerPackagePricing;
use App\Support\ResellerContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreResellerPackagePricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ResellerPackagePricing::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $hasContext = app(ResellerContext::class)->hasReseller();

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_custom' => ['sometimes', 'boolean'],
            // Only required/used when the caller has no resolved reseller
            // context (an ISP admin creating pricing on a reseller's behalf)
            // — a reseller owner/staff always gets attributed to their own
            // context reseller regardless of what's sent here, same pattern
            // as RegistrationService's referrer attribution.
            'reseller_id' => [
                Rule::requiredIf(! $hasContext),
                Rule::exists(Reseller::class, 'id')->where('tenant_id', $this->user()->tenant_id),
            ],
        ];
    }
}
