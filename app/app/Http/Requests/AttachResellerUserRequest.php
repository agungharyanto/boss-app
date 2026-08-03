<?php

namespace App\Http\Requests;

use App\Enums\ResellerUserRole;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class AttachResellerUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageUsers', $this->route('reseller'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Reseller $reseller */
        $reseller = $this->route('reseller');

        return [
            'user_id' => [
                'required',
                Rule::exists(User::class, 'id')->where('tenant_id', $reseller->tenant_id),
            ],
            'role' => ['required', new Enum(ResellerUserRole::class)],
        ];
    }
}
