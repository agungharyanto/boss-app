<?php

namespace App\Services\Commission;

use App\Enums\ReferrerType;
use App\Http\Middleware\EnsureAdminPanelAccess;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\CustomerTimelineEntry;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Models\User;
use App\Services\Billing\RenewalInvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sprint "perpanjang-daftar-pelanggan" — aksi "Perpanjang" di Daftar
 * Pelanggan. Mencatat perpanjangan langganan (opsional ganti paket) +
 * komisi Titip kalau acting user adalah Referrer Sales/Freelance.
 *
 * REVISI (sprint "perpanjang-invoice-asli-cetak", keputusan Agung): aksi
 * "Perpanjang" sekarang JUGA membuat Invoice ASLI per periode + langsung
 * LUNAS lewat InvoiceService::markPaid() (via RenewalInvoiceService) — yang
 * OTOMATIS men-trigger pematangan Komisi Penjualan v0.9.5
 * (CommissionLedgerMaturityService) tanpa logic komisi baru. Titip TETAP
 * terpisah (baris commission_ledger scheme=titip), tidak berubah.
 *
 * BATASAN KERAS (dikonfirmasi berulang di CLAUDE.md untuk seluruh cluster
 * komisi):
 *  - HANYA data BOSS App. TIDAK ADA satu pun panggilan ke NAS / RouterOS /
 *    FreeRADIUS / MixRadius. Perpanjangan layanan yang sebenarnya tetap
 *    proses manual admin di luar BOSS App.
 *  - `GenerateDueInvoices` (job recurring otomatis) TETAP OFF —
 *    `SubscriptionService` tidak dipakai. Invoice yang dibuat di sini
 *    ON-DEMAND, dipicu manual. Subscription "asli" tetap tidak diaktifkan;
 *    RenewalInvoiceService memakai 1 baris `subscriptions` tersembunyi
 *    per pelanggan (`status = Cancelled`, invisible untuk GenerateDueInvoices)
 *    semata sebagai "vehicle" karena `invoices.subscription_id` NOT NULL.
 *  - `customers.ppp_package_id` tetap sumber kebenaran paket (independen
 *    dari `subscriptions`, lihat catatan v0.9.4 di CLAUDE.md).
 *  - Ganti paket = update `customers.ppp_package_id` SEKALI (bukan per
 *    bulan) untuk seluruh transaksi.
 *  - Komisi: `ReferrerType::Sales` / `ReferrerType::Freelance` → `amount`
 *    dari `CommissionRate.titip_amount`, PER baris/bulan. `Teknisi` /
 *    tidak tertaut Referrer → `amount` NULL (baris tetap dibuat sebagai
 *    penanda "periode ini sudah dibayar").
 *  - CREATE-ONLY: tidak ada jalur edit/hapus baris `commission_ledger`.
 *
 * MULTI-BULAN (admin only) — sekolah/instansi yang bayar sekaligus untuk
 * beberapa bulan. `$months`/`$startPeriod` HANYA boleh > 1 / non-null dari
 * jalur admin (di-enforce di sini juga, tidak cuma di UI). Referrer
 * self-service selalu implisit 1 bulan, periode berjalan. Satu verifikasi
 * OTP + satu update paket + satu entri timeline untuk seluruh rentang;
 * N baris `commission_ledger` (satu per bulan).
 */
class SubscriptionRenewalService
{
    public function __construct(
        private readonly ReferrerTitipService $titip,
        private readonly RenewalInvoiceService $renewalInvoice,
    ) {}

    /**
     * @param  int  $months  jumlah bulan (>= 1). > 1 hanya untuk admin.
     * @param  ?Carbon  $startPeriod  bulan awal rentang (default: bulan berjalan).
     * @return array{
     *     package_changed: bool,
     *     package_from: ?string,
     *     package_to: ?string,
     *     months: int,
     *     periods: list<string>,
     *     rows_created: int,
     *     commission_created: bool,
     *     commission_amount: ?float,
     *     commission_total: ?float,
     *     commission_gross_amount: ?float,
     *     commission_skipped_reason: ?string,
     *     invoices_created: int,
     *     invoices_paid: int,
     *     invoice_numbers: list<string>,
     *     invoice_grand_total: float,
     *     sales_commission_matured: int,
     * }
     *
     * @throws \RuntimeException kalau: paket baru tidak valid; multi-bulan
     *                           dipanggil non-admin; ATAU ada periode dalam
     *                           rentang yang sudah tercatat bayar (BLOKIR
     *                           KERAS — tidak ada override lewat aplikasi).
     */
    public function renew(
        User $actor,
        Customer $customer,
        ?int $newPppPackageId,
        int $months = 1,
        ?Carbon $startPeriod = null,
    ): array {
        $months = max(1, $months);

        if ($months > 1 && ! EnsureAdminPanelAccess::userHasAccess($actor)) {
            throw new \RuntimeException('Perpanjang multi-bulan hanya untuk admin.');
        }

        $start = ($startPeriod ? $startPeriod->copy() : Carbon::now())->startOfMonth();

        /** @var list<CarbonImmutable> $periods */
        $periods = [];
        for ($i = 0; $i < $months; $i++) {
            $periods[] = CarbonImmutable::instance($start)->addMonths($i)->startOfMonth();
        }

        // BLOKIR KERAS anti bayar-dobel — cek SETIAP periode dalam rentang.
        // Berlaku untuk SEMUA pemanggil. Kalau ada satu saja yang bentrok,
        // tolak seluruh transaksi dengan menyebut periode yang bentrok.
        $conflicts = [];
        foreach ($periods as $period) {
            if ($this->titip->existingForMonth($customer, Carbon::instance($period)) !== null) {
                $conflicts[] = $period->translatedFormat('F Y');
            }
        }

        if ($conflicts !== []) {
            $list = implode(', ', $conflicts);
            $suffix = $months === 1
                ? ' — hubungi admin kalau ada kebutuhan koreksi.'
                : ', tidak bisa lanjut.';

            throw new \RuntimeException(
                count($conflicts) === 1
                    ? "Periode {$list} sudah tercatat bayar{$suffix}"
                    : "Periode berikut sudah tercatat bayar: {$list} — tidak bisa lanjut."
            );
        }

        $originalPackageId = $customer->ppp_package_id;
        $fromName = $customer->pppPackage?->name;

        $newPackage = null;
        if ($newPppPackageId !== null && $newPppPackageId !== $originalPackageId) {
            $newPackage = PppPackage::query()
                ->where('id', $newPppPackageId)
                ->where('tenant_id', $customer->tenant_id)
                ->where('is_active', true)
                ->first();

            if ($newPackage === null) {
                throw new \RuntimeException('Paket yang dipilih tidak valid atau tidak aktif.');
            }
        }

        $referrer = Referrer::query()
            ->where('user_id', $actor->id)
            ->where('is_active', true)
            ->first();

        $result = [
            'package_changed' => false,
            'package_from' => $fromName,
            'package_to' => $fromName,
            'months' => $months,
            'periods' => array_map(fn (CarbonImmutable $p) => $p->format('Y-m'), $periods),
            'rows_created' => 0,
            'commission_created' => false,
            'commission_amount' => null,
            'commission_total' => null,
            'commission_gross_amount' => null,
            'commission_skipped_reason' => null,
            'invoices_created' => 0,
            'invoices_paid' => 0,
            'invoice_numbers' => [],
            'invoice_grand_total' => 0.0,
            'sales_commission_matured' => 0,
        ];

        DB::transaction(function () use (&$result, $actor, $customer, $newPackage, $originalPackageId, $fromName, $referrer, $periods, $months): void {
            // Ganti paket SEKALI untuk seluruh transaksi.
            if ($newPackage !== null) {
                $customer->update(['ppp_package_id' => $newPackage->id]);
                $customer->refresh();

                $result['package_changed'] = true;
                $result['package_to'] = $newPackage->name;
            }

            $eligibleType = $referrer !== null
                && in_array($referrer->type, [ReferrerType::Sales, ReferrerType::Freelance], true);

            $withCommission = false;
            if (! $eligibleType) {
                $result['commission_skipped_reason'] = $referrer === null
                    ? 'Akun tidak tertaut ke Referral — tidak ada komisi.'
                    : "Tipe Referral {$referrer->type->label()} tidak menghasilkan komisi Titip.";
            } else {
                $availability = $this->titip->availabilityFor($customer);
                if (! $availability['available']) {
                    $result['commission_skipped_reason'] = $availability['reason'] ?? 'Rate komisi Titip tidak tersedia untuk paket ini.';
                } else {
                    $withCommission = true;
                }
            }

            // gross_amount per baris = sell_price paket EFEKTIF (snapshot).
            $effectivePackage = $newPackage
                ?? PppPackage::withoutGlobalScopes()->find($customer->ppp_package_id);
            $grossAmount = $effectivePackage !== null ? (float) $effectivePackage->sell_price : null;

            $perRowCommission = null;
            $invoiceIds = [];
            foreach ($periods as $period) {
                $ledger = $this->titip->recordTitipForPeriod(
                    customer: $customer,
                    referrer: $referrer,
                    period: $period,
                    grossAmount: $grossAmount,
                    withCommission: $withCommission,
                    directlyRecordedBy: $actor,
                );

                if ($withCommission && $ledger->amount !== null) {
                    $perRowCommission = (float) $ledger->amount;
                }

                // Invoice ASLI per periode + langsung LUNAS lewat
                // InvoiceService::markPaid() — yang men-trigger pematangan
                // Komisi Penjualan v0.9.5 tanpa logic komisi baru.
                $issued = $this->renewalInvoice->issuePaidForPeriod($customer, $period);
                $invoiceIds[] = $issued['invoice']->id;
                $result['invoice_numbers'][] = $issued['invoice']->invoice_number;
                $result['invoice_grand_total'] += (float) $issued['invoice']->grand_total;
                if ($issued['created']) {
                    $result['invoices_created']++;
                }
                if ($issued['newly_paid']) {
                    $result['invoices_paid']++;
                }
            }

            // Berapa baris Komisi Penjualan (referral resmi, NON-titip) yang
            // matang sebagai EFEK SAMPING invoice-invoice ini lunas.
            $result['sales_commission_matured'] = CommissionLedger::query()
                ->withoutGlobalScopes()
                ->whereIn('invoice_id', $invoiceIds)
                ->count();

            $result['rows_created'] = count($periods);
            $result['commission_gross_amount'] = $grossAmount;

            if ($withCommission && $perRowCommission !== null) {
                $result['commission_created'] = true;
                $result['commission_amount'] = $perRowCommission;
                $result['commission_total'] = $perRowCommission * count($periods);
            }

            // SATU entri timeline untuk seluruh rentang.
            $firstP = $periods[0]->translatedFormat('F Y');
            $lastP = $periods[array_key_last($periods)]->translatedFormat('F Y');
            $rangeText = $months === 1 ? $firstP : "{$firstP}–{$lastP}";

            $desc = $months === 1
                ? 'Perpanjangan dicatat (periode '.$firstP.')'
                : "Perpanjangan {$months} bulan dicatat, periode {$rangeText}";

            if ($result['package_changed']) {
                $desc .= ", paket diubah dari {$fromName} ke {$result['package_to']}";
            }

            if ($result['invoice_numbers'] !== []) {
                $desc .= '. Invoice: '.implode(', ', $result['invoice_numbers']);
            }

            CustomerTimelineEntry::create([
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->id,
                'event_type' => 'subscription_renewed',
                'description' => $desc,
                'changes' => [
                    'months' => $months,
                    'periods' => $result['periods'],
                    'package_from_id' => $originalPackageId,
                    'package_to_id' => $customer->ppp_package_id,
                    'package_from' => $fromName,
                    'package_to' => $result['package_to'],
                    'rows_created' => $result['rows_created'],
                    'commission_created' => $result['commission_created'],
                    'commission_amount' => $result['commission_amount'],
                    'commission_total' => $result['commission_total'],
                    'referrer_id' => $referrer?->id,
                    'invoice_numbers' => $result['invoice_numbers'],
                    'invoices_created' => $result['invoices_created'],
                    'invoices_paid' => $result['invoices_paid'],
                    'invoice_grand_total' => $result['invoice_grand_total'],
                    'sales_commission_matured' => $result['sales_commission_matured'],
                ],
                'actor_id' => $actor->id,
            ]);
        });

        return $result;
    }
}
