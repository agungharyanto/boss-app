<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // No Rule::unique('customers', 'nik') here on purpose — nik is
            // an `encrypted` cast (randomized ciphertext, same plaintext
            // never matches itself in raw SQL), so uniqueness is checked
            // separately below via nik_hash, a deterministic HMAC.
            'nik' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'package' => ['nullable', 'string', 'max:255'],
            'referred_by_referrer_id' => [
                'nullable', 'integer',
                Rule::exists('referrers', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }

    /**
     * Enforces the same "unique NIK per tenant" rule the old
     * Rule::unique('customers', 'nik') used to, but via nik_hash — the only
     * thing that can actually be compared for an `encrypted` column.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $nik = $this->input('nik');

            if (! $nik) {
                return;
            }

            if (Customer::nikAlreadyExists($nik, $this->user()->tenant_id)) {
                $validator->errors()->add('nik', 'NIK sudah terdaftar.');
            }
        });
    }
}
