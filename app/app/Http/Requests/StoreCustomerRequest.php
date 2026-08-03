<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Reseller;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Customer::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string'],
            'phone_number' => ['required', 'string', 'max:20'],
            // Only meaningful for an ISP admin creating a customer directly
            // under a reseller — CreateCustomerAction ignores this and uses
            // the resolved reseller context instead whenever the caller is
            // operating as a reseller owner/staff.
            'reseller_id' => [
                'nullable',
                Rule::exists(Reseller::class, 'id')->where('tenant_id', $this->user()->tenant_id),
            ],
        ];
    }
}
