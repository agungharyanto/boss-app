<?php

namespace App\Services;

use App\Models\CommissionRate;
use App\Models\PppPackage;

/**
 * v0.9.3 — Commission Rate Settings. Semua business logic lewat sini
 * (BOSS-006): CommissionRateController maupun
 * App\Livewire\Commission\CommissionRateIndex sama-sama memanggil service
 * ini, bukan HTTP internal ke API sendiri.
 *
 * `tenant_id` diisi eksplisit dari paketnya (bukan diandalkan ke hook
 * BelongsToTenant) supaya rate selalu berada di tenant yang sama persis
 * dengan PppPackage-nya, terlepas dari konteks pemanggil — sama pola dengan
 * CommissionLedger di RegistrationService.
 */
class CommissionRateService
{
    /**
     * @param  array{recurring_amount?: mixed, limited_count_amount?: mixed, limited_count_times?: mixed, titip_amount?: mixed, payout_window_start_day?: mixed, payout_window_end_day?: mixed, is_active?: bool}  $data
     */
    public function createForPackage(PppPackage $package, array $data): CommissionRate
    {
        return CommissionRate::create([
            ...$this->normalize($data),
            'tenant_id' => $package->tenant_id,
            'ppp_package_id' => $package->id,
        ]);
    }

    /**
     * @param  array{recurring_amount?: mixed, limited_count_amount?: mixed, limited_count_times?: mixed, titip_amount?: mixed, is_active?: bool}  $data
     */
    public function update(CommissionRate $rate, array $data): CommissionRate
    {
        $rate->update($this->normalize($data));

        return $rate->refresh();
    }

    /**
     * Soft delete — sebuah rate yang pernah dipakai menghitung komisi
     * (v0.9.4+) tidak boleh hilang total dari jejak historis.
     */
    public function delete(CommissionRate $rate): void
    {
        $rate->delete();
    }

    /**
     * String kosong dari form ('') -> null, supaya kolom nullable benar-benar
     * tersimpan null, bukan 0/"".
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        foreach ([
            'recurring_amount', 'limited_count_amount', 'limited_count_times', 'titip_amount',
            'payout_window_start_day', 'payout_window_end_day',
        ] as $key) {
            if (array_key_exists($key, $data) && ($data[$key] === '' || $data[$key] === null)) {
                $data[$key] = null;
            }
        }

        return $data;
    }
}
