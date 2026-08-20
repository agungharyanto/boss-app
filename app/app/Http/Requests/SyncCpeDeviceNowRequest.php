<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncCpeDeviceNowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('cpe_device'));
    }

    /**
     * No body — sync takes no parameters.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
