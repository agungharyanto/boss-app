<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\PppPackage;
use App\Services\Billing\PppoeVlan10MigrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * v0.12.2 Track A — jalankan SEKALI (idempoten, aman diulang) untuk 527
 * pelanggan PPPoE VLAN10 `test-x86-bajastu`. Sumber: CSV export mixradius
 * `Exported_Customers-ppp_20260910_013942.csv` (path via --csv).
 *
 * TIDAK menyentuh radcheck/radreply (sudah ditulis terpisah, Langkah 4,
 * lewat App\Services\Network\RadcheckWriterService) — command ini murni
 * customers.ppp_package_id + subscriptions + invoices + invoice_line_items.
 *
 * Default DRY RUN (laporan saja). --commit untuk benar-benar menulis.
 */
class MigratePppoeVlan10TrackA extends Command
{
    protected $signature = 'app:migrate-pppoe-vlan10-track-a
        {--csv= : path CSV export mixradius (default: storage/app/imports/mixradius-ppp-vlan10-20260910.csv)}
        {--commit : benar-benar menulis (default dry-run)}';

    protected $description = 'Migrasi Track A: customers.ppp_package_id + subscriptions + invoices untuk 527 pelanggan PPPoE VLAN10 (test-x86)';

    /** @var array<string> */
    private const ANOMALI = [
        'agung-tokia', 'anten-palestina', 'admin', 'hambalang', 'hambalang-baru',
        'homebase@tokia.net.id', '088985106713', '085880795147', '0882008304318',
    ];

    /** @var array<string, string> mixradius Plan -> BOSS PppPackage name */
    private const PLAN_TO_PACKAGE_NAME = [
        'PPPOE-REMOTE' => 'PPPoE-Remote',
        'HomeFixed-10Mbps-Loyalis' => 'HomeFixed-10Mbps',
        'HomeFixed-30Mbps-Loyalis' => 'HomeFixed-30Mbps',
        'HomeFixed-50Mbps-Loyalis' => 'HomeFixed-50Mbps',
    ];

    public function handle(PppoeVlan10MigrationService $migration): int
    {
        $path = $this->option('csv') ?: storage_path('app/imports/mixradius-ppp-vlan10-20260910.csv');
        if (! is_file($path)) {
            $this->error("CSV tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');

        $packagesByName = PppPackage::withoutGlobalScopes()->whereNull('deleted_at')
            ->get(['id', 'name', 'sell_price'])->keyBy('name');

        $byPhone = [];
        $byMember = [];
        Customer::withoutGlobalScopes()
            ->get(['id', 'name', 'phone_number', 'legacy_username', 'legacy_mixradius_member_id', 'tenant_id', 'reseller_id', 'ppp_package_id', 'tax_billable'])
            ->each(function (Customer $c) use (&$byPhone, &$byMember) {
                if ($c->phone_number) {
                    $byPhone[trim($c->phone_number)] = $c;
                }
                if ($c->legacy_username) {
                    $byPhone[trim($c->legacy_username)] ??= $c;
                }
                if ($c->legacy_mixradius_member_id) {
                    $byMember[trim((string) $c->legacy_mixradius_member_id)] = $c;
                }
            });

        $rows = [];
        $fh = fopen($path, 'r');
        $hdr = fgetcsv($fh, 0, ',', '"', '');
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $rows[] = array_combine($hdr, $r);
        }
        fclose($fh);

        $today = Carbon::now('Asia/Jakarta')->startOfDay();
        $skipAnomali = 0;
        $skipNoCustomer = 0;
        $skipEmptyPool = 0;
        $processed = 0;
        $samples = [];
        $errors = [];

        // Plan -> Framed-Pool mapping (Langkah 4, identik) hanya dipakai di
        // sini untuk MENENTUKAN 7 pelanggan yang di-skip total (plan tanpa
        // pool BOSS sama sekali) — bukan untuk Framed-Pool itu sendiri
        // (sudah final di radcheck/radreply).
        $planHasPool = [
            'PPPOE-REMOTE' => true,
            'HomeFixed-10Mbps-Loyalis' => true,
            'HomeFixed-30Mbps-Loyalis' => true,
            'HomeFixed-50Mbps-Loyalis' => true,
            'HomeFixed-20Mbps' => false,
            'Free-PPPoE' => false,
            'HomeFixed-10Mbps' => false,
        ];

        foreach ($rows as $r) {
            $login = trim($r['Login']);
            $phone = trim($r['Phone']);
            $member = trim($r['CustomerId']);

            if (in_array($login, self::ANOMALI, true)) {
                $skipAnomali++;

                continue;
            }

            $customer = $byPhone[$login] ?? $byPhone[$phone] ?? $byMember[$member] ?? null;
            if ($customer === null) {
                $skipNoCustomer++;

                continue;
            }

            $plan = trim($r['Plan']);
            $auth = $r['AuthStatus'] === '1';

            // Sama definisi Langkah 4: 7 pelanggan AKTIF (AuthStatus=1)
            // dengan plan tanpa pool BOSS sama sekali (Free-PPPoE dkk) DAN
            // Expired kosong/0000-00-00 (dianggap "belum expired" ->
            // tier 3_active) di-skip TOTAL — nol radcheck/subscription/
            // invoice untuk mereka. Tier 1/2 (auth=0, atau auth=1 tapi
            // sudah lewat Expired) TIDAK PERNAH di-skip oleh kondisi ini,
            // sekalipun plan mereka sama — Framed-Pool mereka sudah tetap
            // (fallback PPPOE-REMOTE / Isolir) di Langkah 4, ppp_package_id
            // di sini boleh tetap NULL kalau plan tak dikenal.
            $expRaw = trim($r['Expired'] ?? '');
            $isUnexpired = $expRaw === '' || $expRaw === '0000-00-00 00:00:00';
            if ($auth && ! ($planHasPool[$plan] ?? false) && $isUnexpired) {
                $skipEmptyPool++;

                continue;
            }

            $pkgName = self::PLAN_TO_PACKAGE_NAME[$plan] ?? null;
            $package = $pkgName !== null ? ($packagesByName[$pkgName] ?? null) : null;

            $expiresAt = $isUnexpired ? null : Carbon::parse($expRaw, 'Asia/Jakarta')->startOfDay();

            $renRaw = trim($r['Renewed'] ?? '');
            $startedAt = ($renRaw && $renRaw !== '0000-00-00 00:00:00') ? Carbon::parse($renRaw, 'Asia/Jakarta')->startOfDay() : null;
            if ($startedAt === null) {
                $startedAt = $expiresAt?->copy()->subMonthNoOverflow();
            }

            $billingCycleDay = $expiresAt?->day ?? 1;

            $amount = $package !== null
                ? (float) $package->sell_price
                : (float) (($r['Total'] ?? '') !== '' ? $r['Total'] : ($r['Price'] ?? 0));

            $lineDescription = $package !== null
                ? "Paket {$package->name} — migrasi PPPoE VLAN10 test-x86"
                : "Paket {$plan} (CSV mixradius) — migrasi PPPoE VLAN10 test-x86";

            $subscriptionName = $package !== null ? $package->name : "Migrasi PPPoE VLAN10 (Track A) — {$plan}";

            if ($commit) {
                try {
                    $result = $migration->migrateOne(
                        $customer,
                        $package?->id,
                        $subscriptionName,
                        $amount,
                        $lineDescription,
                        $startedAt,
                        $expiresAt,
                        $billingCycleDay,
                        (bool) $customer->tax_billable,
                    );
                } catch (\Throwable $e) {
                    $errors[] = "cust#{$customer->id} {$customer->name} ({$login}): ".$e->getMessage();

                    continue;
                }
            } else {
                $result = [
                    'subscription_id' => null, 'invoice_id' => null, 'invoice_number' => '(dry-run)',
                    'invoice_status' => ($expiresAt === null || ! $expiresAt->lt($today)) ? 'paid' : 'overdue',
                    'invoice_created' => true,
                ];
            }

            $processed++;
            if (count($samples) < 40) {
                $samples[] = [
                    'cust_id' => $customer->id, 'name' => $customer->name, 'login' => $login,
                    'plan' => $plan, 'pkg' => $package?->name ?? '(NULL)', 'ppp_package_id' => $package?->id ?? 'NULL',
                    'expires_at' => $expiresAt?->toDateString() ?? 'NULL', 'auth' => $auth ? 'y' : 'n',
                    'invoice_status' => $result['invoice_status'], 'invoice_number' => $result['invoice_number'],
                ];
            }
        }

        $this->info(($commit ? '=== EKSEKUSI (COMMIT) ===' : '=== DRY RUN (--commit untuk tulis beneran) ==='));
        $this->info('CSV total: '.count($rows));
        $this->info("anomali skip: {$skipAnomali} | no-customer skip: {$skipNoCustomer} | empty-pool skip: {$skipEmptyPool}");
        $this->info("DIPROSES: {$processed}");
        if ($errors !== []) {
            $this->error('ERROR ('.count($errors).'):');
            foreach ($errors as $e) {
                $this->error("  {$e}");
            }
        }
        foreach (array_slice($samples, 0, 15) as $s) {
            $this->line(sprintf(
                '  cust#%-4d %-24s login=%-16s plan=%-24s pkg=%s(id=%s) expires_at=%s auth=%s -> %s %s',
                $s['cust_id'], mb_substr($s['name'], 0, 24), $s['login'], $s['plan'], $s['pkg'], $s['ppp_package_id'],
                $s['expires_at'], $s['auth'], $s['invoice_status'], $s['invoice_number'],
            ));
        }

        return self::SUCCESS;
    }
}
