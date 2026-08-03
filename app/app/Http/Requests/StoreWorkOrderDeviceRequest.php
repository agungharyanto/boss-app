<?php

namespace App\Http\Requests;

use App\Enums\WorkOrderDeviceType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderDeviceRequest extends FormRequest
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
            'device_type' => ['required', Rule::enum(WorkOrderDeviceType::class)],
            'mac_address' => ['required', 'string', 'max:255'],
            'serial_number' => ['required', 'string', 'max:255'],
        ];
    }
}
