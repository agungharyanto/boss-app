<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\ResellerPackagePricing;
use App\Models\Subscription;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Subscription::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => [
                'required',
                Rule::exists(Customer::class, 'id')->where('tenant_id', $this->user()->tenant_id),
            ],
            // When given, this pricing row is authoritative for name/price
            // (see SubscriptionService::create) — name/monthly_amount below
            // only matter for a direct-retail (no pricing) subscription.
            'reseller_package_pricing_id' => [
                'nullable',
                Rule::exists(ResellerPackagePricing::class, 'id'),
            ],
            'name' => ['required_without:reseller_package_pricing_id', 'nullable', 'string', 'max:255'],
            'monthly_amount' => ['required_without:reseller_package_pricing_id', 'nullable', 'numeric', 'min:0'],
            'billing_cycle_day' => ['required', 'integer', 'min:1', 'max:31'],
            'started_at' => ['nullable', 'date'],
        ];
    }
}
