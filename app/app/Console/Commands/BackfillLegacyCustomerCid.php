<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;

/**
 * One-time fix for the 28 MixRadius-imported customers created BEFORE
 * CustomerIdGeneratorService learned to short-circuit on
 * legacy_mixradius_member_id — their cid was already auto-assigned via the
 * normal {code}-{sequential} generator at the time. Overwrites cid to match
 * legacy_mixradius_member_id exactly, bypassing the Eloquent model (cid is
 * deliberately not fillable/mass-assignable — see CustomerObserver::assignCid())
 * the same way that method itself does: forceFill()+saveQuietly(), no
 * 'profile_updated' timeline entry for what is bookkeeping, not a real
 * profile change. Safe to re-run — skips any row where cid already matches.
 */
class BackfillLegacyCustomerCid extends Command
{
    protected $signature = 'customers:backfill-legacy-cid';

    protected $description = 'Timpa cid customer hasil import legacy MixRadius supaya persis sama dengan legacy_mixradius_member_id';

    public function handle(): int
    {
        $customers = Customer::withoutGlobalScopes()
            ->whereNotNull('legacy_mixradius_member_id')
            ->get();

        $updated = 0;

        foreach ($customers as $customer) {
            if ($customer->cid === $customer->legacy_mixradius_member_id) {
                continue;
            }

            $customer->forceFill(['cid' => $customer->legacy_mixradius_member_id])->saveQuietly();
            $updated++;
        }

        $this->info("{$updated} customer cid diperbarui menjadi legacy_mixradius_member_id (dari total ".$customers->count().' customer legacy import).');

        return self::SUCCESS;
    }
}
