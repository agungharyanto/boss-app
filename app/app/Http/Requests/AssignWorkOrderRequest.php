<?php

namespace App\Http\Requests;

use App\Models\Technician;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('work_order'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'technician_id' => [
                'required',
                Rule::exists(Technician::class, 'id')->where('tenant_id', $this->user()->tenant_id),
            ],
        ];
    }
}
