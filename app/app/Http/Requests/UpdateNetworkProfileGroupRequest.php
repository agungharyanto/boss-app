<?php

namespace App\Http\Requests;

use App\Enums\NetworkProfileGroupType;
use App\Models\CustomerIpPool;
use App\Models\NetworkProfileGroup;
use App\Support\ProfilPaketAttributeLabels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * See StoreNetworkProfileGroupRequest's own docblock — same rules,
 * adjusted for partial updates.
 */
class UpdateNetworkProfileGroupRequest extends FormRequest
{
    /** @var NetworkProfileGroup */
    private $group;

    public function authorize(): bool
    {
        return $this->user()->can('manage', NetworkProfileGroup::class);
    }

    protected function prepareForValidation(): void
    {
        $this->group = $this->route('network_profile_group');

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
                Rule::unique(NetworkProfileGroup::class, 'name')
                    ->where('nas_id', $this->input('nas_id', $this->group->nas_id))
                    ->whereNull('deleted_at')
                    ->ignore($this->group->id),
            ],
            'type' => ['sometimes', 'required', 'string', 'in:hotspot,ppp'],
            'customer_ip_pool_id' => [
                'sometimes', 'required', 'integer',
                // See StoreNetworkProfileGroupRequest's own comment on
                // whereNull('deleted_at') — same real bug, same fix.
                Rule::exists('customer_ip_pools', 'id')->where('tenant_id', $this->user()->tenant_id)->whereNull('deleted_at'),
            ],
            'dns_primary' => ['nullable', 'ip'],
            'dns_secondary' => ['nullable', 'ip'],
            'parent_queue' => ['nullable', 'string', 'max:255'],
            'interface_name' => ['nullable', 'string', 'max:255'],
            'service_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validatePoolBelongsToSameNas($validator);
        });
    }

    private function validatePoolBelongsToSameNas(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['nas_id', 'customer_ip_pool_id', 'type'])) {
            return;
        }

        $nasId = (int) $this->input('nas_id', $this->group->nas_id);
        $poolId = (int) $this->input('customer_ip_pool_id', $this->group->customer_ip_pool_id);
        // Plain scoped find() — see StoreNetworkProfileGroupRequest's own
        // comment for why.
        $pool = CustomerIpPool::find($poolId);

        // Real bug caught updating a group whose STORED pool reference had
        // since been soft-deleted (restrictOnDelete() only blocks a hard
        // delete, not a soft one) — editing some UNRELATED field, without
        // ever touching customer_ip_pool_id, sailed through validation and
        // crashed inside NetworkProfileGroupService instead. This check
        // fires even when customer_ip_pool_id itself isn't in the request,
        // since $poolId falls back to the group's own current value.
        if ($pool === null) {
            $validator->errors()->add('customer_ip_pool_id', 'IP Pool yang terhubung ke grup profil ini sudah tidak ada (mungkin sudah dihapus). Pilih IP Pool lain sebelum menyimpan perubahan.');

            return;
        }

        if ($pool->nas_id !== $nasId) {
            $validator->errors()->add('customer_ip_pool_id', 'IP Pool yang dipilih harus milik NAS yang sama.');

            return;
        }

        // v0.14.3.1 — same backend enforcement as
        // StoreNetworkProfileGroupRequest, falling back to the group's own
        // stored type since 'type' is only 'sometimes' present here.
        $groupType = NetworkProfileGroupType::from((string) $this->input('type', $this->group->type->value));

        if (! $pool->usage_type->isCompatibleWith($groupType)) {
            $validator->errors()->add('customer_ip_pool_id', "IP Pool ini bertipe pemakaian \"{$pool->usage_type->label()}\", tidak cocok untuk Grup Profil tipe \"{$groupType->label()}\".");
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
