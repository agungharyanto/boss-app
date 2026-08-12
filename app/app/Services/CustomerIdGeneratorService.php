<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerIdSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generates customers.cid. Three cases, checked in this order — see
 * CustomerObserver::created() for the only place this is actually called
 * from:
 *
 * 1. legacy_mixradius_member_id is set (customer imported/matched from the
 *    legacy MixRadius system) — cid becomes that value verbatim, no
 *    sequence/random generation at all. Keeps a customer's cid stable
 *    across the two systems instead of assigning it a second, unrelated
 *    identity.
 * 2. reseller_id is set — unchanged since the original design:
 *    "{reseller.code}-{number}", number zero-padded to 6 digits,
 *    sequential per (tenant_id, reseller.code).
 * 3. Direct customer (no reseller, not a legacy import) —
 *    "{YYYY}{MM}{4-digit random}" (10 chars total, e.g. "202608" + "4471").
 *    Deliberately random, not sequential — collision-checked against the DB
 *    and retried, never assumed unique just because the space is 10,000
 *    wide.
 */
class CustomerIdGeneratorService
{
    private const MAX_RANDOM_ATTEMPTS = 20;

    public function generate(Customer $customer): string
    {
        if (filled($customer->legacy_mixradius_member_id)) {
            return $customer->legacy_mixradius_member_id;
        }

        if ($customer->reseller_id) {
            return $this->generateForReseller($customer);
        }

        return $this->generateForDirectCustomer($customer);
    }

    private function generateForReseller(Customer $customer): string
    {
        $code = $customer->reseller?->code;

        if (blank($code)) {
            throw new RuntimeException(
                "Tidak bisa generate CID untuk customer #{$customer->id}: kode reseller belum tersedia."
            );
        }

        return DB::transaction(function () use ($code, $customer) {
            $sequence = $this->lockSequence($customer->tenant_id, $code);

            $number = $sequence->next_number;
            $sequence->increment('next_number');

            return $code.'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
        });
    }

    private function generateForDirectCustomer(Customer $customer): string
    {
        $prefix = now()->format('Ym');

        for ($attempt = 0; $attempt < self::MAX_RANDOM_ATTEMPTS; $attempt++) {
            $candidate = $prefix.$this->randomSuffix();

            $exists = Customer::withoutGlobalScopes()
                ->where('tenant_id', $customer->tenant_id)
                ->where('cid', $candidate)
                ->exists();

            if (! $exists) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            "Tidak bisa generate CID unik untuk customer #{$customer->id} setelah ".self::MAX_RANDOM_ATTEMPTS.' percobaan.'
        );
    }

    /**
     * A separate, overridable seam (not inlined into generateForDirectCustomer())
     * purely so a test can force a collision deterministically — see
     * CustomerIdGeneratorServiceTest for the retry-on-collision case.
     */
    protected function randomSuffix(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Race-safe row lock on this (tenant_id, code)'s counter — creates the
     * row on first use for that code. The create() itself can still race
     * between two concurrent transactions that both find no row yet; the
     * unique(tenant_id, code) constraint lets only one insert win, and the
     * loser simply re-selects (with the lock) the row the winner created —
     * never a silent duplicate counter, never a MAX()+1 guess.
     */
    private function lockSequence(int $tenantId, string $code): CustomerIdSequence
    {
        $sequence = CustomerIdSequence::where('tenant_id', $tenantId)
            ->where('code', $code)
            ->lockForUpdate()
            ->first();

        if ($sequence !== null) {
            return $sequence;
        }

        try {
            return CustomerIdSequence::create([
                'tenant_id' => $tenantId,
                'code' => $code,
                'next_number' => 1,
            ]);
        } catch (QueryException) {
            return CustomerIdSequence::where('tenant_id', $tenantId)
                ->where('code', $code)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }
}
