<?php

namespace App\Http\Requests;

use App\Enums\WorkOrderDeviceType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('work_order'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'device_type' => ['required', Rule::enum(WorkOrderDeviceType::class)],
            'mac_address' => ['required', 'string', 'max:255'],
            'serial_number' => ['required', 'string', 'max:255'],
            // v0.12.7 — Tipe Modem, diisi teknisi saat scan device (opsional
            // — pola sama ssid/wifi_password di ProvisionWorkOrderDeviceRequest,
            // teknisi mungkin tidak selalu tahu/isi ini). Sama pola
            // Rule::exists() tenant-scoped yang sudah dipakai
            // RegisterCustomer's own ppp_package_id validation, TAPI juga
            // dibatasi is_active — Tipe Modem yang sudah dinonaktifkan admin
            // tidak boleh dipilih untuk instalasi baru.
            'modem_type_id' => [
                'nullable', 'integer',
                Rule::exists('modem_types', 'id')
                    ->where('tenant_id', $this->route('work_order')->tenant_id)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
