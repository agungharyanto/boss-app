<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PushWanConfigCpeDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('cpe_device'));
    }

    /**
     * No body — Push Konfig Sekarang takes no parameters, sama pola
     * RebootCpeDeviceRequest.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
