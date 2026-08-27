<?php

namespace App\Http\Requests;

use App\Enums\NetworkProfileGroupType;
use App\Models\CustomerIpPool;
use App\Models\NetworkProfileGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * v0.14.3 — see NetworkProfileGroup's own docblock. name is unique per
 * NAS (same pattern as v0.14.1/v0.14.2 — no explicit uniqueness
 * requirement in the sprint brief, extrapolated from the established
 * "Profil Paket" convention).
 */
class StoreNetworkProfileGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', NetworkProfileGroup::class);
    }

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
                Rule::unique(NetworkProfileGroup::class, 'name')
                    ->where('nas_id', $this->input('nas_id'))
                    ->whereNull('deleted_at'),
            ],
            'type' => ['required', 'string', 'in:hotspot,ppp'],
            'customer_ip_pool_id' => [
                'required', 'integer',
                // whereNull('deleted_at') needed for the same reason
                // documented throughout this codebase's other
                // Rule::exists()/Rule::unique() checks — a soft-deleted
                // row would otherwise still "exist" as far as this raw
                // query is concerned. Real bug caught manually verifying
                // this sprint: CustomerIpPool's restrictOnDelete() FK only
                // blocks a HARD delete, never a soft one (soft-delete is
                // just an UPDATE), so a soft-deleted pool could otherwise
                // be selected here with no error at all.
                Rule::exists('customer_ip_pools', 'id')->where('tenant_id', $this->user()->tenant_id)->whereNull('deleted_at'),
            ],
            'dns_primary' => ['nullable', 'ip'],
            'dns_secondary' => ['nullable', 'ip'],
            'parent_queue' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validatePool($validator);
        });
    }

    /**
     * The whole reason the NAS check exists: a Grup Profil referencing an
     * IP Pool from a DIFFERENT NAS would mean pushing a router-local `/ppp
     * profile`'s `remote-address` to a pool name that NEVER EXISTS on
     * that specific router (each NAS's `/ip pool` objects are entirely
     * separate, even if two CustomerIpPool rows happen to share a name).
     *
     * v0.14.3.1 — the usage_type check is the backend enforcement of the
     * same rule the Livewire form's own dropdown filter already applies —
     * never relying on the frontend filter alone (a direct API call
     * bypasses it entirely).
     */
    private function validatePool(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['nas_id', 'customer_ip_pool_id', 'type'])) {
            return;
        }

        $nasId = $this->integer('nas_id');
        // Plain scoped find() (not withoutGlobalScopes()) — the exists
        // rule above already confirmed this id belongs to the right
        // tenant and isn't soft-deleted, so a scoped query here is both
        // sufficient and correctly excludes a soft-deleted row too.
        $pool = CustomerIpPool::find($this->integer('customer_ip_pool_id'));

        if ($pool === null) {
            return;
        }

        if ($pool->nas_id !== $nasId) {
            $validator->errors()->add('customer_ip_pool_id', 'IP Pool yang dipilih harus milik NAS yang sama.');

            return;
        }

        $groupType = NetworkProfileGroupType::from((string) $this->input('type'));

        if (! $pool->usage_type->isCompatibleWith($groupType)) {
            $validator->errors()->add('customer_ip_pool_id', "IP Pool ini bertipe pemakaian \"{$pool->usage_type->label()}\", tidak cocok untuk Grup Profil tipe \"{$groupType->label()}\".");
        }
    }
}
