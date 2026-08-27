<?php

namespace App\Http\Requests;

use App\Models\HotspotPackage;
use App\Models\NetworkProfileGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * See StoreHotspotPackageRequest's own docblock — same rules, adjusted for
 * partial updates.
 */
class UpdateHotspotPackageRequest extends FormRequest
{
    /** @var HotspotPackage */
    private $package;

    public function authorize(): bool
    {
        return $this->user()->can('manage', HotspotPackage::class);
    }

    protected function prepareForValidation(): void
    {
        $this->package = $this->route('hotspot_package');

        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }

        // gte:cost_price (below) compares against whatever's in the
        // SUBMITTED request data — a partial update that only touches
        // sell_price (without resubmitting cost_price) would otherwise
        // compare against a missing value. Merging in the stored value
        // here (only when not already submitted) keeps that comparison
        // meaningful regardless of which price field(s) this request
        // actually touches — same "fall back to the stored value" pattern
        // already established by UpdateNetworkProfileGroupRequest's own
        // nas_id/customer_ip_pool_id/type fallbacks.
        if (! $this->has('cost_price')) {
            $this->merge(['cost_price' => (string) $this->package->cost_price]);
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
                Rule::unique(HotspotPackage::class, 'name')
                    ->where('network_profile_group_id', $this->input('network_profile_group_id', $this->package->network_profile_group_id))
                    ->whereNull('deleted_at')
                    ->ignore($this->package->id),
            ],
            'visible_to_reseller' => ['sometimes', 'boolean'],
            'show_in_voucher_form' => ['sometimes', 'boolean'],
            'cost_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'sell_price' => ['sometimes', 'required', 'numeric', 'min:0', 'gte:cost_price'],
            'promo_price' => ['nullable', 'numeric', 'min:0'],
            'tax_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'profile_type' => ['sometimes', 'required', 'string', 'in:unlimited,limited'],
            'limit_type' => ['required_if:profile_type,limited', 'nullable', 'string', 'in:time_base,quota_base'],
            'active_duration_value' => ['required_if:profile_type,limited', 'nullable', 'integer', 'min:1'],
            'active_duration_unit' => ['required_if:profile_type,limited', 'nullable', 'string', 'in:minute,hour,day,month'],
            'shared_users' => ['sometimes', 'required', 'integer', 'min:1'],
            'priority' => ['nullable', 'string', 'max:50'],
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

    private function validateGroupIsHotspotType(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['network_profile_group_id'])) {
            return;
        }

        $groupId = $this->input('network_profile_group_id', $this->package->network_profile_group_id);
        $group = NetworkProfileGroup::find($groupId);

        if ($group === null) {
            $validator->errors()->add('network_profile_group_id', 'Grup Profil yang terhubung ke Profil Hotspot ini sudah tidak ada (mungkin sudah dihapus). Pilih Grup Profil lain sebelum menyimpan perubahan.');

            return;
        }

        if ($group->type->value !== 'hotspot') {
            $validator->errors()->add('network_profile_group_id', 'Grup Profil yang dipilih harus bertipe Hotspot.');
        }
    }
}
