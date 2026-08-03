<?php

namespace App\Http\Requests;

use App\Enums\WorkOrderPhotoType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderPhotoRequest extends FormRequest
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
            'type' => ['required', Rule::enum(WorkOrderPhotoType::class)],
            'file' => ['required', 'image', 'max:10240'],
        ];
    }
}
