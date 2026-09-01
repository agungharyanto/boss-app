<?php

namespace App\Http\Requests;

use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Support\ProfilPaketAttributeLabels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * See StorePppPackageRequest's own docblock — same rules, adjusted for
 * partial updates.
 */
class UpdatePppPackageRequest extends FormRequest
{
    /** @var PppPackage */
    private $package;

    public function authorize(): bool
    {
        return $this->user()->can('manage', PppPackage::class);
    }

    protected function prepareForValidation(): void
    {
        $this->package = $this->route('ppp_package');

        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'network_profile_group_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('network_profile_groups', 'id')->where('tenant_id', $this->user()->tenant_id)->whereNull('deleted_at'),
            ],
            'bandwidth_profile_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('bandwidth_profiles', 'id')->where('tenant_id', $this->user()->tenant_id)->whereNull('deleted_at'),
            ],
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique(PppPackage::class, 'name')
                    ->where('network_profile_group_id', $this->input('network_profile_group_id', $this->package->network_profile_group_id))
                    ->whereNull('deleted_at')
                    ->ignore($this->package->id),
            ],
            'visible_to_reseller' => ['sometimes', 'boolean'],
            'cost_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'sell_price' => ['sometimes', 'required', 'numeric', 'min:0', 'gte:cost_price'],
            'promo_price' => ['nullable', 'numeric', 'min:0'],
            'tax_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'active_duration_value' => ['sometimes', 'required', 'integer', 'min:1'],
            'active_duration_unit' => ['sometimes', 'required', 'string', 'in:minute,hour,day,month'],
            'shared_users' => ['sometimes', 'required', 'integer', 'min:1'],
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

    private function validateGroupIsPppType(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['network_profile_group_id'])) {
            return;
        }

        $groupId = (int) $this->input('network_profile_group_id', $this->package->network_profile_group_id);
        $group = NetworkProfileGroup::find($groupId);

        if ($group === null) {
            return;
        }

        if ($group->type->value !== 'ppp') {
            $validator->errors()->add('network_profile_group_id', 'Grup Profil yang dipilih harus bertipe PPP.');
        }
    }

    /**
     * See StorePppPackageRequest::validateNoNameCollisionOnNas()'s own
     * docblock. Falls back to the package's own currently-stored
     * network_profile_group_id/name since both are only 'sometimes'
     * present here — an update touching an unrelated field must still be
     * checked against its actual current values, not skipped just because
     * this request didn't resubmit them.
     */
    private function validateNoNameCollisionOnNas(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['network_profile_group_id', 'name'])) {
            return;
        }

        $groupId = (int) $this->input('network_profile_group_id', $this->package->network_profile_group_id);
        $group = NetworkProfileGroup::find($groupId);

        if ($group === null) {
            return;
        }

        $name = (string) $this->input('name', $this->package->name);

        if (PppPackage::collidesWithExistingName($group->nas_id, $name, $this->package->id)) {
            $validator->errors()->add('name', 'Nama ini sudah dipakai Grup Profil atau Profil PPP lain di NAS yang sama — nama /ppp profile harus unik per NAS di router.');
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
