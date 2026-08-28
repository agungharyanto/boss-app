<?php

namespace App\Http\Requests;

use App\Models\CustomerIpPool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Revisi Grup Profil (Langkah 3) — its own Form Request, deliberately
 * separate from Store/UpdateNasRequest so a plain `PUT /nas/{nas}` never
 * silently touches this field without going through NasService::
 * updateExpiredIpPool()'s own RouterOS live-push dispatch. `customer_ip_pool_id`
 * (nullable) — omit/null clears the expired fallback profile. The
 * same-NAS check is done HERE (not left solely to the Service's own
 * InvalidArgumentException) so a bad request cleanly 422s like every
 * other FormRequest in this codebase, matching Store/
 * UpdateNetworkProfileGroupRequest's own validatePool() pattern — the
 * Service's own check stays as a defense-in-depth backstop for the
 * Livewire entry point, which doesn't go through a FormRequest at all.
 */
class UpdateExpiredProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('nas'));
    }

    public function rules(): array
    {
        return [
            'customer_ip_pool_id' => [
                'nullable', 'integer',
                Rule::exists('customer_ip_pools', 'id')->where('tenant_id', $this->user()->tenant_id)->whereNull('deleted_at'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('customer_ip_pool_id') || $this->input('customer_ip_pool_id') === null) {
                return;
            }

            $pool = CustomerIpPool::find($this->integer('customer_ip_pool_id'));

            if ($pool !== null && $pool->nas_id !== $this->route('nas')->id) {
                $validator->errors()->add('customer_ip_pool_id', 'IP Pool yang dipilih harus milik NAS yang sama.');
            }
        });
    }
}
