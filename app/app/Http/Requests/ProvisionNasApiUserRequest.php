<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Deliberately its own Form Request, never merged with StoreNasRequest/
 * UpdateNasRequest — admin_username/admin_password must never share a
 * field/model binding with nas.api_username/api_password, the exact mixup
 * that caused the credential-rotation bug this whole flow replaces. See
 * NasApiUserProvisioningService's docblock.
 */
class ProvisionNasApiUserRequest extends FormRequest
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
            'admin_username' => ['required', 'string', 'max:255'],
            'admin_password' => ['required', 'string', 'max:255'],
        ];
    }
}
