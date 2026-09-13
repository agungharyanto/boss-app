<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * v0.12.7 Langkah 3 — POST /work-orders/{work_order}/confirm. Authorize
 * ke `manage` (sama pola StoreWorkOrderDeviceRequest/
 * ProvisionWorkOrderDeviceRequest — WorkOrderPolicy::manage() sudah
 * mencakup teknisi assigned/claimed sejak v0.12.3) — teknisi yang belum
 * claim/di-assign ke WO ini tidak boleh mengonfirmasinya.
 */
class ConfirmWorkOrderRequest extends FormRequest
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
            'code' => ['required', 'string'],
        ];
    }
}
