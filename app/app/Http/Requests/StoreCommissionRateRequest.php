<?php

namespace App\Http\Requests;

use App\Models\CommissionRate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * v0.9.3 — buat konfigurasi rate komisi untuk satu PppPackage. Satu rate
 * aktif per paket (unique parsial di commission_rates); untuk mengubah rate
 * paket yang sudah punya, pakai UpdateCommissionRateRequest.
 */
class StoreCommissionRateRequest extends FormRequest
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
            'ppp_package_id' => [
                'required', 'integer',
                // Paket harus milik tenant pemanggil dan belum di-soft-delete.
                // Rule::exists() query tabel mentah (tanpa global scope), jadi
                // tenant_id di-filter eksplisit di sini.
                Rule::exists('ppp_packages', 'id')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->whereNull('deleted_at'),
                // Satu rate aktif per paket. whereNull('deleted_at') supaya
                // rate lama yang sudah dihapus tidak memblokir pembuatan baru
                // (unique index-nya parsial, lihat migration).
                Rule::unique('commission_rates', 'ppp_package_id')->whereNull('deleted_at'),
            ],
            'recurring_amount' => ['nullable', 'numeric', 'min:0'],
            'limited_count_amount' => ['nullable', 'numeric', 'min:0'],
            'limited_count_times' => ['nullable', 'integer', 'min:1'],
            'titip_amount' => ['nullable', 'numeric', 'min:0'],
            'payout_window_start_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'payout_window_end_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (CommissionRate::schemeErrors(
                $this->input('recurring_amount'),
                $this->input('limited_count_amount'),
                $this->input('limited_count_times'),
                $this->input('titip_amount'),
            ) as $field => $message) {
                $validator->errors()->add($field, $message);
            }

            foreach (CommissionRate::payoutWindowErrors(
                $this->input('payout_window_start_day'),
                $this->input('payout_window_end_day'),
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
            'ppp_package_id' => 'Paket PPP',
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
