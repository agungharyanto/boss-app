<?php

namespace App\Http\Requests;

use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Support\ProfilPaketAttributeLabels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * v0.14.5 — Profil PPP. name is unique per network_profile_group_id (same
 * baseline "unique per parent" convention as HotspotPackage's own name/
 * network_profile_group_id uniqueness) — but that alone does NOT catch the
 * real collision risk this sub-version is built around: see
 * validateNoNameCollisionOnNas()'s own docblock.
 *
 * Same "sell_price >= cost_price only, no automatic reseller-fee
 * calculation" simple price validation as StoreHotspotPackageRequest.
 */
class StorePppPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', PppPackage::class);
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
                Rule::unique(PppPackage::class, 'name')
                    ->where('network_profile_group_id', $this->input('network_profile_group_id'))
                    ->whereNull('deleted_at'),
            ],
            'visible_to_reseller' => ['sometimes', 'boolean'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'sell_price' => ['required', 'numeric', 'min:0', 'gte:cost_price'],
            'promo_price' => ['nullable', 'numeric', 'min:0'],
            'tax_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'active_duration_value' => ['required', 'integer', 'min:1'],
            'active_duration_unit' => ['required', 'string', 'in:minute,hour,day,month'],
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
            $this->validateGroupIsPppType($validator);
            $this->validateNoNameCollisionOnNas($validator);
        });
    }

    /**
     * A Profil PPP pushes its own `/ppp profile` — meaningless for a Grup
     * Profil whose type is Hotspot (that push mechanism, and the RouterOS
     * object behind it, are entirely different — see NetworkProfileGroup's
     * own docblock).
     */
    private function validateGroupIsPppType(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['network_profile_group_id'])) {
            return;
        }

        $group = NetworkProfileGroup::find($this->integer('network_profile_group_id'));

        if ($group === null) {
            return;
        }

        if ($group->type->value !== 'ppp') {
            $validator->errors()->add('network_profile_group_id', 'Grup Profil yang dipilih harus bertipe PPP.');
        }
    }

    /**
     * ATURAN NAMA FINAL (2026-09-05, dikonfirmasi Agung) — nama Profil PPP
     * BOLEH sama dengan Grup Profil tipe ppp / Profil PPP lain (dunia PPP
     * bebas, collision `/ppp profile` di router di-handle otomatis via
     * PppPackage::routerOsProfileName() saat push). Yang TETAP diblokir di
     * sini: nama sama dengan dunia HOTSPOT (Grup Profil tipe hotspot ATAU
     * Profil Hotspot) di NAS yang sama. Lihat
     * PppPackage::collidesWithExistingName()'s own docblock.
     */
    private function validateNoNameCollisionOnNas(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['network_profile_group_id', 'name'])) {
            return;
        }

        $group = NetworkProfileGroup::find($this->integer('network_profile_group_id'));

        if ($group === null) {
            return;
        }

        if (PppPackage::collidesWithExistingName($group->nas_id, (string) $this->input('name'))) {
            $validator->errors()->add('name', 'Nama ini sudah dipakai Grup Profil Hotspot atau Profil Hotspot di NAS yang sama — nama Paket/Profil PPP tidak boleh bentrok dengan dunia Hotspot.');
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
