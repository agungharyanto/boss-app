<?php

namespace App\Http\Requests;

use App\Models\BandwidthProfile;
use App\Support\ProfilPaketAttributeLabels;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Values here are always Kbps — see StoreBandwidthProfileRequest's own
 * docblock for why (unit-picker is a Livewire-UI convenience layer only).
 */
class UpdateBandwidthProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', BandwidthProfile::class);
    }

    /**
     * See StoreBandwidthProfileRequest's own docblock — same real bug,
     * same fix: trim BEFORE validation runs so Rule::unique() below
     * compares the same value that will actually end up stored.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var BandwidthProfile $profile */
        $profile = $this->route('bandwidth_profile');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                // See StoreBandwidthProfileRequest's own comment on
                // whereNull('deleted_at') — same reasoning applies here.
                Rule::unique(BandwidthProfile::class, 'name')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->whereNull('deleted_at')
                    ->ignore($profile->id),
            ],
            'upload_min' => ['sometimes', 'required', 'integer', 'min:1'],
            'upload_max' => ['sometimes', 'required', 'integer', 'gte:upload_min'],
            'download_min' => ['sometimes', 'required', 'integer', 'min:1'],
            'download_max' => ['sometimes', 'required', 'integer', 'gte:download_min'],
            'is_active' => ['sometimes', 'boolean'],
        ];
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
