<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SetCpeWifiCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('cpe_device'));
    }

    /**
     * Both optional, but at least one required (required_without on each
     * other) — matches App\Services\Network\CpeActionService::
     * setWifiCredentials()'s own defensive guard. Length bounds follow the
     * 802.11/WPA-PSK spec: SSID up to 32 bytes, a WPA-PSK passphrase is
     * 8-63 ASCII characters.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ssid' => ['nullable', 'required_without:password', 'string', 'max:32'],
            'password' => ['nullable', 'required_without:ssid', 'string', 'min:8', 'max:63'],
        ];
    }
}
