<?php

namespace App\Http\Requests;

use App\Enums\ReferrerType;
use App\Models\Referrer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreReferrerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Referrer::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required', 'string', 'max:30',
                Rule::unique(Referrer::class, 'phone')->where('tenant_id', $this->user()->tenant_id),
            ],
            'type' => ['required', new Enum(ReferrerType::class)],
            'is_active' => ['sometimes', 'boolean'],
            'create_login_account' => ['sometimes', 'boolean'],
        ];
    }
}
