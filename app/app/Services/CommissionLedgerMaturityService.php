<?php

namespace App\Services;

use App\Enums\CommissionStatus;
use App\Models\CommissionLedger;
use App\Models\Invoice;

/**
 * v0.9.5 — pematangan komisi otomatis (Pending → Eligible).
 *
 * Dipanggil HANYA dari App\Services\InvoiceService::markPaid() — satu-satunya
 * jalur sah "invoice pelanggan lunas" (dua pemanggil sah: PATCH manual v0.3.4
 * dan webhook Xendit v0.3.5, keduanya lewat markPaid()). Begitu invoice lunas,
 * baris `commission_ledger` milik pelanggan invoice itu yang masih Pending DAN
 * sudah punya `amount` (diisi v0.9.4 dari CommissionRate saat registrasi / saat
 * admin men-set referrer) otomatis naik ke Eligible.
 *
 * Yang SENGAJA TIDAK ikut matang:
 *  - Baris Pending TANPA `amount` (referrer diisi tapi tanpa skema — perilaku
 *    backward-compatible v0.9.4): tidak ada nominal yang bisa dibayarkan, jadi
 *    dibiarkan Pending sampai admin melengkapi skema/rate-nya.
 *  - Baris yang sudah Eligible/Approved/Paid/Rejected: terminal dari sisi hook
 *    ini, tidak pernah disentuh lagi.
 *
 * "Skip = gugur, bukan tertunda": kalau pelanggan skip pembayaran, markPaid()
 * memang tidak pernah dipanggil untuk periode itu → baris tetap Pending, tidak
 * ada yang keliru jadi Eligible. Catatan: model per-periode / append-satu-baris-
 * per-pembayaran BELUM ada di skema `commission_ledger` (tidak ada kolom
 * period/invoice_id/urutan) — lihat CHANGELOG v0.9.5 bagian "Batas struktur".
 *
 * Tenant-eksplisit, tidak bergantung Auth — markPaid() bisa berjalan dari
 * webhook Xendit tanpa user login (sama alasan & pola seperti
 * TaxCalculationService::writeLedgerEntry()).
 */
class CommissionLedgerMaturityService
{
    /**
     * @return int jumlah baris commission_ledger yang berubah jadi Eligible
     */
    public function matureForPaidInvoice(Invoice $invoice): int
    {
        $rows = CommissionLedger::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('customer_id', $invoice->customer_id)
            ->where('status', CommissionStatus::Pending->value)
            ->whereNotNull('amount')
            ->get();

        foreach ($rows as $row) {
            $trace = "v0.9.5: Eligible otomatis — invoice {$invoice->invoice_number} lunas ".now()->toDateString();
            $row->update([
                'status' => CommissionStatus::Eligible,
                'notes' => $row->notes ? $row->notes."\n".$trace : $trace,
            ]);
        }

        return $rows->count();
    }
}
