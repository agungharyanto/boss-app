<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Toggle Aktif/Nonaktif for one WLANConfiguration instance (2026-08-17) —
 * see App\Services\Network\CpeActionService::setSsidEnabled()'s own
 * docblock. `max:8` matches SetCpeWifiCredentialsRequest's own ceiling
 * (real fleet never observed beyond index 5).
 */
class SetCpeSsidEnabledRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('cpe_device'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ssid_index' => ['required', 'integer', 'min:1', 'max:8'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
