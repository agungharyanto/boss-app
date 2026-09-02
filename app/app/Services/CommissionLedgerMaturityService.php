<?php

namespace App\Services;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Models\CommissionLedger;
use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\Invoice;

/**
 * v0.9.5 (redesain append-per-invoice, dikonfirmasi Agung) — komisi
 * diperoleh PER INVOICE LUNAS, bukan sekali flip.
 *
 * Dipanggil HANYA dari App\Services\InvoiceService::markPaid() — satu-satunya
 * jalur sah "invoice pelanggan lunas" (dua pemanggil: PATCH manual v0.3.4 +
 * webhook Xendit v0.3.5). Setiap kali sebuah invoice pelanggan lunas:
 *
 *  - **Skema `recurring`**: satu baris komisi Eligible per invoice lunas,
 *    tanpa batas — komisi bulanan berlanjut selama pelanggan bayar.
 *  - **Skema `limited_count`**: sama (satu baris per invoice lunas), TAPI
 *    di-cap ke `CommissionRate::limited_count_times`. Setelah N baris komisi
 *    "diperoleh" (Eligible/Approved/Paid), invoice lunas berikutnya TIDAK
 *    lagi menghasilkan baris komisi.
 *  - **Skip pembayaran** (invoice tidak pernah lunas): markPaid() tidak
 *    pernah dipanggil → tidak ada baris komisi untuk periode itu.
 *    "Gugur, bukan tertunda" tercapai natural — nol logic tambahan.
 *
 * **Baris "template" v0.9.4** (dibuat saat registrasi / saat admin men-set
 * referrer, status Pending, invoice_id NULL): invoice PERTAMA yang lunas
 * mematangkan baris INI di tempat (Eligible + invoice_id, amount di-refresh
 * dari rate saat ini) — bukan membuat baris terpisah. Invoice berikutnya
 * baru genuinely membuat baris baru (append). Baris template TANPA scheme
 * (referrer diisi tanpa memilih skema — backward-compatible v0.9.4) tidak
 * pernah menghasilkan komisi sampai admin melengkapi skema-nya.
 *
 * Idempoten berlapis: (1) `commission_ledger.invoice_id` unik parsial di
 * DB, (2) cek eksplisit di sini, (3) InvoiceService::transition() sudah
 * menjamin markPaid() hanya "menang" sekali (Paid→Paid ditolak state
 * machine).
 *
 * Tenant-eksplisit, tidak bergantung Auth — markPaid() bisa jalan dari
 * webhook Xendit tanpa user login (pola sama seperti
 * TaxCalculationService::writeLedgerEntry()).
 */
class CommissionLedgerMaturityService
{
    /**
     * @return int 1 jika sebuah baris komisi dimatangkan/dibuat untuk invoice ini, 0 jika tidak
     */
    public function matureForPaidInvoice(Invoice $invoice): int
    {
        // Idempotensi: invoice ini sudah pernah menghasilkan baris komisi?
        if (CommissionLedger::query()->withoutGlobalScopes()->where('invoice_id', $invoice->id)->exists()) {
            return 0;
        }

        $customer = Customer::withoutGlobalScopes()->find($invoice->customer_id);

        if ($customer === null || $customer->referred_by_referrer_id === null) {
            return 0;
        }

        // Baris "template" v0.9.4 = baris pertama untuk (customer, referrer) ini.
        // Sumber kebenaran skema komisi yang dipilih admin.
        $templateRow = CommissionLedger::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('customer_id', $customer->id)
            ->where('referrer_id', $customer->referred_by_referrer_id)
            ->orderBy('id')
            ->first();

        $scheme = $templateRow?->scheme?->value;

        // Tidak ada skema (referrer diisi tanpa memilih skema, atau tidak ada
        // baris template sama sekali) → tidak ada komisi yang bisa dibayarkan.
        if ($scheme === null) {
            return 0;
        }

        $rate = CommissionRate::query()
            ->withoutGlobalScopes()
            ->where('ppp_package_id', $customer->ppp_package_id)
            ->where('is_active', true)
            ->first();

        $amount = $rate?->amountForScheme($scheme);

        // Rate paket tidak (lagi) punya nominal untuk skema ini → jangan menebak.
        if ($amount === null) {
            return 0;
        }

        if ($scheme === CommissionScheme::LimitedCount->value) {
            $timesAllowed = (int) $rate->limited_count_times;

            $earnedSoFar = CommissionLedger::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $invoice->tenant_id)
                ->where('customer_id', $customer->id)
                ->where('referrer_id', $customer->referred_by_referrer_id)
                ->where('scheme', CommissionScheme::LimitedCount->value)
                ->whereIn('status', [
                    CommissionStatus::Eligible->value,
                    CommissionStatus::Approved->value,
                    CommissionStatus::Paid->value,
                ])
                ->count();

            if ($earnedSoFar >= $timesAllowed) {
                return 0;
            }
        }

        $trace = "v0.9.5: komisi Eligible — invoice {$invoice->invoice_number} lunas ".now()->toDateString();

        // Invoice PERTAMA yang lunas: matangkan baris template di tempat.
        if ($templateRow->status === CommissionStatus::Pending && $templateRow->invoice_id === null) {
            $templateRow->update([
                'status' => CommissionStatus::Eligible,
                'amount' => $amount,
                'invoice_id' => $invoice->id,
                'notes' => $templateRow->notes ? $templateRow->notes."\n".$trace : $trace,
            ]);

            return 1;
        }

        // Invoice berikutnya: baris komisi genuinely baru (append-only).
        CommissionLedger::create([
            'tenant_id' => $invoice->tenant_id,
            'referrer_id' => $customer->referred_by_referrer_id,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'scheme' => $scheme,
            'amount' => $amount,
            'status' => CommissionStatus::Eligible,
            'notes' => $trace,
        ]);

        return 1;
    }
}
