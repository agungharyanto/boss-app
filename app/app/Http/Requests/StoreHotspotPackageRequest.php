<?php

namespace App\Http\Requests;

use App\Models\HotspotPackage;
use App\Models\NetworkProfileGroup;
use App\Support\ProfilPaketAttributeLabels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * v0.14.4 — Profil Hotspot. name is unique per network_profile_group_id
 * (same "unique per parent, not per tenant" convention as
 * NetworkProfileGroup's own name/nas_id uniqueness and CustomerIpPool's
 * name/nas_id uniqueness).
 *
 * Deliberately simple price validation per the sprint's own explicit
 * instruction: sell_price >= cost_price only, no automatic reseller-fee
 * calculation (flagged as a real, ambiguous business-rule question in the
 * Langkah 0 report — not invented here without confirmation).
 */
class StoreHotspotPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', HotspotPackage::class);
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
            'network_profile_group_id' => [
                'required', 'integer',
                Rule::exists('network_profile_groups', 'id')->where('tenant_id', $this->user()->tenant_id)->whereNull('deleted_at'),
            ],
            'bandwidth_profile_id' => [
                'required', 'integer',
                Rule::exists('bandwidth_profiles', 'id')->where('tenant_id', $this->user()->tenant_id)->whereNull('deleted_at'),
            ],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique(HotspotPackage::class, 'name')
                    ->where('network_profile_group_id', $this->input('network_profile_group_id'))
                    ->whereNull('deleted_at'),
            ],
            'visible_to_reseller' => ['sometimes', 'boolean'],
            'show_in_voucher_form' => ['sometimes', 'boolean'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'sell_price' => ['required', 'numeric', 'min:0', 'gte:cost_price'],
            'promo_price' => ['nullable', 'numeric', 'min:0'],
            'tax_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'profile_type' => ['required', 'string', 'in:unlimited,limited'],
            'limit_type' => ['required_if:profile_type,limited', 'nullable', 'string', 'in:time_base,quota_base'],
            'active_duration_value' => ['required_if:profile_type,limited', 'nullable', 'integer', 'min:1'],
            'active_duration_unit' => ['required_if:profile_type,limited', 'nullable', 'string', 'in:minute,hour,day,month'],
            // v0.14.4 amendment — "Kuota"/"Satuan Data", required ONLY when
            // limit_type=quota_base, and explicitly PROHIBITED otherwise
            // (never both required_if AND left as dead data when
            // limit_type is time_base or profile_type is unlimited) — see
            // the quota migration's own docblock for why these are never
            // pushed to RouterOS this sub-version.
            'quota_value' => ['required_if:limit_type,quota_base', 'prohibited_unless:limit_type,quota_base', 'nullable', 'numeric', 'min:0.01'],
            'quota_unit' => ['required_if:limit_type,quota_base', 'prohibited_unless:limit_type,quota_base', 'nullable', 'string', 'in:mb,gb'],
            'shared_users' => ['required', 'integer', 'min:1'],
            'priority' => ['nullable', 'integer', 'between:1,8'],
            'login_days' => ['nullable', 'array'],
            'login_days.*' => ['string', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'login_start_time' => ['nullable', 'date_format:H:i'],
            'login_end_time' => ['nullable', 'date_format:H:i', 'after:login_start_time'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateGroupIsHotspotType($validator);
        });
    }

    /**
     * The whole reason this check exists: pushing a Profil Hotspot to
     * `/ip hotspot user profile` only makes sense for a Grup Profil that
     * itself targets `/ppp profile`-adjacent Hotspot infrastructure — a
     * Grup Profil of type PPP has no RouterOS Hotspot server behind it at
     * all.
     */
    private function validateGroupIsHotspotType(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['network_profile_group_id'])) {
            return;
        }

        $group = NetworkProfileGroup::find($this->integer('network_profile_group_id'));

        if ($group === null) {
            return;
        }

        if ($group->type->value !== 'hotspot') {
            $validator->errors()->add('network_profile_group_id', 'Grup Profil yang dipilih harus bertipe Hotspot.');
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
