<?php

namespace App\Http\Requests;

use App\Enums\ContactAccessLevel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerContactRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('contact'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone_number' => ['sometimes', 'required', 'string', 'max:20'],
            'relationship' => ['nullable', 'string', 'max:255'],
            'access_level' => ['sometimes', 'required', 'string', Rule::enum(ContactAccessLevel::class)],
            'can_view_billing' => ['sometimes', 'boolean'],
            'can_request_service_change' => ['sometimes', 'boolean'],
            'can_receive_notifications' => ['sometimes', 'boolean'],
            'is_authorized_contact' => ['sometimes', 'boolean'],
        ];
    }
}
