<?php

namespace App\Http\Requests;

use App\Enums\ResellerStatus;
use App\Models\Reseller;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateResellerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('reseller'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Reseller $reseller */
        $reseller = $this->route('reseller');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'nullable', 'string', 'max:255',
                Rule::unique(Reseller::class, 'slug')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->ignore($reseller->id),
            ],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', new Enum(ResellerStatus::class)],
        ];
    }
}
