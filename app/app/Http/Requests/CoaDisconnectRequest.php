<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CoaDisconnectRequest extends FormRequest
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
            'username' => ['required', 'string', 'max:255'],
        ];
    }
}
