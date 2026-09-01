<?php

namespace App\Services;

use App\Enums\CommissionStatus;
use App\Models\CommissionLedger;
use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\Referrer;

/**
 * v0.9.4 — satu tempat pembuatan baris `commission_ledger` Pending untuk
 * atribusi referral, dipakai bareng oleh RegistrationService (registrasi
 * pelanggan baru) dan App\Livewire\Customers\CustomerShow (admin men-set
 * referrer untuk pelanggan existing yang sebelumnya belum punya).
 *
 * Resolusi nominal:
 *  - `$scheme` null                      → scheme NULL, amount NULL
 *    (perilaku v0.9.3 & sebelumnya — backward compatible, tidak maksa).
 *  - `$scheme` diisi TAPI paket tidak
 *    punya CommissionRate aktif dengan
 *    amount untuk skema itu              → scheme NULL, amount NULL
 *    (aman — form UI hanya menawarkan skema yang valid, ini jaring
 *    pengaman untuk jalur API / race).
 *  - `$scheme` diisi & amount ada        → scheme + amount terisi dari
 *    CommissionRate milik `customer.ppp_package_id`.
 */
class CommissionAttributionService
{
    public function createPendingLedger(Customer $customer, Referrer $referrer, ?string $scheme = null): CommissionLedger
    {
        $resolvedScheme = null;
        $amount = null;

        if ($scheme !== null && $customer->ppp_package_id !== null) {
            $rate = CommissionRate::where('ppp_package_id', $customer->ppp_package_id)
                ->where('is_active', true)
                ->first();

            $resolvedAmount = $rate?->amountForScheme($scheme);

            if ($resolvedAmount !== null) {
                $resolvedScheme = $scheme;
                $amount = $resolvedAmount;
            }
        }

        return CommissionLedger::create([
            'tenant_id' => $customer->tenant_id,
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'scheme' => $resolvedScheme,
            'amount' => $amount,
            'status' => CommissionStatus::Pending,
        ]);
    }
}
