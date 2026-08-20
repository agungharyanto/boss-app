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
     * `ssid_index` (2026-08-17, per-SSID "Ganti WiFi" on the CPE detail
     * page) is optional — omitted entirely by callers that only ever meant
     * "the main SSID" (the public API v1 endpoint, unchanged) and defaults
     * to 1 in the controller in that case. `max:8` matches the real fleet
     * ceiling confirmed during the multi-SSID discovery work — no device
     * in this fleet has ever shown an index beyond 5, this leaves a little
     * headroom rather than hard-coding exactly what's been observed so far.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ssid' => ['nullable', 'required_without:password', 'string', 'max:32'],
            'password' => ['nullable', 'required_without:ssid', 'string', 'min:8', 'max:63'],
            'ssid_index' => ['nullable', 'integer', 'min:1', 'max:8'],
        ];
    }
}
