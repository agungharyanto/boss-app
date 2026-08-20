<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Safety net for `customers` rows whose nik is set but nik_hash is still
 * null — normally impossible going forward (Customer::booted()'s saving()
 * hook always derives nik_hash from nik), but can happen for a row written
 * before the encrypted cast + nik_hash migration was deployed on a given
 * server, or via any future path that bypasses the Eloquent model (a raw
 * import script, a direct DB write). Safe to re-run — matches nothing once
 * every row is caught up.
 *
 * Deliberately bypasses the Eloquent Customer model entirely (Crypt::
 * encryptString()/decryptString() directly instead — the exact same
 * primitive the `encrypted` cast uses under the hood, so the result is
 * indistinguishable from a normal model save). Found the hard way: loading
 * a row whose `nik` column still holds raw plaintext into a Customer model,
 * then calling ->save(), throws DecryptException from INSIDE Eloquent's own
 * isDirty()/getDirty() — it decrypts the ORIGINAL stored value to compare
 * against the new one for an encrypted-cast attribute, which fails the
 * instant "original" isn't valid ciphertext to begin with. Going around the
 * model avoids that comparison altogether.
 */
class BackfillCustomerNikHash extends Command
{
    protected $signature = 'customers:backfill-nik-hash';

    protected $description = 'Backfill nik_hash untuk customer yang nik-nya terisi tapi nik_hash masih kosong (aman dijalankan berulang)';

    public function handle(): int
    {
        $rows = DB::table('customers')
            ->whereNotNull('nik')
            ->where('nik', '!=', '')
            ->whereNull('nik_hash')
            ->select('id', 'nik')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Tidak ada customer yang perlu di-backfill.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            try {
                // Already valid ciphertext (e.g. a save that ran before
                // this migration added nik_hash) — decrypt to get the
                // plaintext back so the hash can be derived from it.
                $plainNik = Crypt::decryptString($row->nik);
            } catch (DecryptException) {
                // Raw plaintext NIK, written before the encrypted cast was
                // deployed on this server.
                $plainNik = $row->nik;
            }

            DB::table('customers')->where('id', $row->id)->update([
                'nik' => Crypt::encryptString($plainNik),
                'nik_hash' => hash_hmac('sha256', $plainNik, config('app.nik_hmac_key')),
            ]);
        }

        $this->info("Backfilled nik_hash untuk {$rows->count()} customer.");

        return self::SUCCESS;
    }
}
