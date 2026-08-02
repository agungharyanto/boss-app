<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegistrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('register-customer');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => [
                'required', 'string', 'max:20',
                Rule::unique('customers', 'phone_number')->where('tenant_id', $tenantId),
            ],
            'address' => ['required', 'string'],
            'nik' => [
                'nullable', 'string', 'max:20',
                Rule::unique('customers', 'nik')->where('tenant_id', $tenantId),
            ],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'package' => ['nullable', 'string', 'max:255'],
            'referred_by_agent_id' => [
                'nullable', 'integer',
                Rule::exists('agents', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
