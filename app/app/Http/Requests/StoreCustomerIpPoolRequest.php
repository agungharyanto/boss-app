<?php

namespace App\Http\Requests;

use App\Models\CustomerIpPool;
use App\Services\Network\CustomerIpPoolService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * v0.14.2 — see CustomerIpPool's own docblock for why this is a distinct
 * concept from VpnIpPool. Validation here is deliberately "dasar, tidak
 * terlalu ketat" per the sprint brief: valid IPs, start<=end, and
 * gateway/range fall somewhere inside the given CIDR — not a strict
 * "excludes network/broadcast" host-count check.
 */
class StoreCustomerIpPoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', CustomerIpPool::class);
    }

    /**
     * Same trim-before-validate fix already applied to
     * StoreBandwidthProfileRequest (v0.14.1) — trim BEFORE Rule::unique()
     * runs so it compares the same value that will actually end up stored.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'nas_id' => [
                'required', 'integer',
                Rule::exists('nas', 'id')->where('tenant_id', $this->user()->tenant_id),
            ],
            'name' => [
                'required', 'string', 'max:255',
                // Unique PER NAS, not per tenant — two different NAS may
                // each have a pool named the same thing. whereNull
                // ('deleted_at') needed for the same reason documented in
                // StoreBandwidthProfileRequest: Rule::unique() queries the
                // raw table directly, bypassing SoftDeletingScope.
                Rule::unique(CustomerIpPool::class, 'name')
                    ->where('nas_id', $this->input('nas_id'))
                    ->whereNull('deleted_at'),
            ],
            'network_address' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/'],
            'gateway_ip' => ['required', 'ip'],
            'range_start' => ['required', 'ip'],
            'range_end' => ['required', 'ip'],
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

    private function validateRangeOrder(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['range_start', 'range_end'])) {
            return;
        }

        $start = ip2long((string) $this->input('range_start'));
        $end = ip2long((string) $this->input('range_end'));

        if ($start !== false && $end !== false && $start > $end) {
            $validator->errors()->add('range_end', 'Range end harus >= range start.');
        }
    }

    private function validateCidrContainment(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['network_address', 'gateway_ip', 'range_start', 'range_end'])) {
            return;
        }

        $cidr = (string) $this->input('network_address');

        foreach (['gateway_ip' => 'Gateway IP', 'range_start' => 'Range start', 'range_end' => 'Range end'] as $field => $label) {
            $ip = (string) $this->input($field);

            if (! CustomerIpPoolService::ipWithinCidr($ip, $cidr)) {
                $validator->errors()->add($field, "{$label} harus berada di dalam network address {$cidr}.");
            }
        }
    }

    private function validateNoOverlap(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['nas_id', 'range_start', 'range_end'])) {
            return;
        }

        $nasId = $this->integer('nas_id');
        $start = (string) $this->input('range_start');
        $end = (string) $this->input('range_end');

        if (app(CustomerIpPoolService::class)->overlapsExistingRange($nasId, $start, $end)) {
            $validator->errors()->add('range_start', 'Range ini tumpang tindih dengan pool lain yang sudah ada di NAS ini.');
        }
    }
}
