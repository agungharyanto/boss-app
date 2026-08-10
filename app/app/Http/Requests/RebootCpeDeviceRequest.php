<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RebootCpeDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('cpe_device'));
    }

    /**
     * No body — reboot takes no parameters.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
