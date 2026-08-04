<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('nas'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // mikrotik_ip sengaja tidak bisa di-set manual lewat endpoint ini
            // di v0.6.1 — lihat StoreNasRequest.
            'api_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'api_username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'api_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'radius_secret' => ['sometimes', 'string', 'max:255'],
            'coa_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
