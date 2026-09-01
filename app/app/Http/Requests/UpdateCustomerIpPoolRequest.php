<?php

namespace App\Http\Requests;

use App\Models\CustomerIpPool;
use App\Services\Network\CustomerIpPoolService;
use App\Support\ProfilPaketAttributeLabels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * See StoreCustomerIpPoolRequest's own docblock — same rules, adjusted for
 * partial updates (`sometimes`) and excluding the pool itself from the
 * unique-name and overlap checks.
 */
class UpdateCustomerIpPoolRequest extends FormRequest
{
    /** @var CustomerIpPool */
    private $pool;

    public function authorize(): bool
    {
        return $this->user()->can('manage', CustomerIpPool::class);
    }

    protected function prepareForValidation(): void
    {
        $this->pool = $this->route('customer_ip_pool');

        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'nas_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('nas', 'id')->where('tenant_id', $this->user()->tenant_id),
            ],
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique(CustomerIpPool::class, 'name')
                    ->where('nas_id', $this->input('nas_id', $this->pool->nas_id))
                    ->whereNull('deleted_at')
                    ->ignore($this->pool->id),
            ],
            'usage_type' => ['sometimes', 'required', 'string', 'in:ppp,hotspot,general'],
            'network_address' => ['sometimes', 'required', 'string', 'regex:/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/'],
            'gateway_ip' => ['sometimes', 'required', 'ip'],
            'range_start' => ['sometimes', 'required', 'ip'],
            'range_end' => ['sometimes', 'required', 'ip'],
            'dns_primary' => ['nullable', 'ip'],
            'dns_secondary' => ['nullable', 'ip'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateRangeOrder($validator);
            $this->validateCidrContainment($validator);
            $this->validateNoOverlap($validator);
        });
    }

    private function effective(string $field): string
    {
        return (string) $this->input($field, $this->pool->{$field});
    }

    private function validateRangeOrder(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['range_start', 'range_end'])) {
            return;
        }

        $start = ip2long($this->effective('range_start'));
        $end = ip2long($this->effective('range_end'));

        if ($start !== false && $end !== false && $start > $end) {
            $validator->errors()->add('range_end', 'Range end harus >= range start.');
        }
    }

    private function validateCidrContainment(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['network_address', 'gateway_ip', 'range_start', 'range_end'])) {
            return;
        }

        $cidr = $this->effective('network_address');

        foreach (['gateway_ip' => 'Gateway IP', 'range_start' => 'Range start', 'range_end' => 'Range end'] as $field => $label) {
            if (! CustomerIpPoolService::ipWithinCidr($this->effective($field), $cidr)) {
                $validator->errors()->add($field, "{$label} harus berada di dalam network address {$cidr}.");
            }
        }
    }

    private function validateNoOverlap(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['nas_id', 'range_start', 'range_end'])) {
            return;
        }

        $nasId = (int) $this->input('nas_id', $this->pool->nas_id);
        $start = $this->effective('range_start');
        $end = $this->effective('range_end');

        if (app(CustomerIpPoolService::class)->overlapsExistingRange($nasId, $start, $end, $this->pool->id)) {
            $validator->errors()->add('range_start', 'Range ini tumpang tindih dengan pool lain yang sudah ada di NAS ini.');
        }
    }

    /**
     * Revisi Pesan Error Bahasa Indonesia — nama field di pesan validasi
     * (mis. "Harga Jual wajib diisi." bukan "The sell price field is
     * required.") lewat satu sumber tunggal dipakai lintas seluruh cluster
     * "Profil Paket" — lihat ProfilPaketAttributeLabels sendiri.
     */
    public function attributes(): array
    {
        return ProfilPaketAttributeLabels::forFormRequest();
    }
}
