<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * v0.7.5 bridge endpoint — CS/admin types in whatever a technician relayed
 * by phone/personal WhatsApp, NOT a technician-facing self-service form
 * (that's still backlog, v0.11.0 Mobile Self-Service Portal / a future
 * 2-way WhatsApp bot — see docs/ROADMAP.md). Genuinely partial-update
 * (`sometimes` on both fields, not `required_without` each other) on
 * purpose: SSID and password often arrive across two separate phone calls,
 * unlike v0.7.4's SetCpeWifiCredentialsRequest, which always fires one
 * immediate, standalone action.
 */
class ProvisionWorkOrderDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('work_order'));
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'ssid' => ['sometimes', 'nullable', 'string', 'max:32'],
            'wifi_password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:63'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('ssid') && ! $this->has('wifi_password')) {
                $validator->errors()->add('ssid', 'Minimal salah satu dari ssid/wifi_password harus diisi.');
            }
        });
    }
}
