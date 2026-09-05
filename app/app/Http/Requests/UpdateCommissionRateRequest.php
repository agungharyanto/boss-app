<?php

namespace App\Http\Requests;

use App\Models\CommissionRate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * v0.9.3 — ubah rate komisi paket yang sudah punya. `ppp_package_id` TIDAK
 * bisa diubah di sini (rate terikat ke paketnya seumur hidup — untuk paket
 * lain, buat rate baru); field itu diabaikan kalau ikut dikirim.
 */
class UpdateCommissionRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', CommissionRate::class);
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'recurring_amount', 'limited_count_amount', 'limited_count_times', 'titip_amount',
            'payout_window_start_day', 'payout_window_end_day',
        ] as $key) {
            if ($this->input($key) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'recurring_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'limited_count_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'limited_count_times' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'titip_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payout_window_start_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'payout_window_end_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        /** @var CommissionRate $rate */
        $rate = $this->route('commission_rate');

        // Field yang tidak ikut dikirim di request ini di-fallback ke nilai
        // tersimpan — sama pola dengan UpdateHotspotPackageRequest/
        // UpdateNetworkProfileGroupRequest — supaya mengubah satu field saja
        // tetap divalidasi melawan pasangan/minimal-1 yang benar.
        $validator->after(function (Validator $validator) use ($rate): void {
            foreach (CommissionRate::schemeErrors(
                $this->input('recurring_amount', $rate->recurring_amount),
                $this->input('limited_count_amount', $rate->limited_count_amount),
                $this->input('limited_count_times', $rate->limited_count_times),
                $this->input('titip_amount', $rate->titip_amount),
            ) as $field => $message) {
                $validator->errors()->add($field, $message);
            }

            foreach (CommissionRate::payoutWindowErrors(
                $this->input('payout_window_start_day', $rate->payout_window_start_day),
                $this->input('payout_window_end_day', $rate->payout_window_end_day),
            ) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'recurring_amount' => 'Komisi Per Bulan',
            'limited_count_amount' => 'Komisi Skema X-Kali',
            'limited_count_times' => 'Jumlah Kali Pembayaran',
            'titip_amount' => 'Komisi Titip',
            'payout_window_start_day' => 'Dari Tanggal',
            'payout_window_end_day' => 'Sampai Tanggal',
            'is_active' => 'Status Aktif',
        ];
    }
}
