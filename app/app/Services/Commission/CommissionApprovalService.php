<?php

namespace App\Services\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Models\CommissionLedger;
use App\Models\User;
use RuntimeException;

/**
 * v0.9.0 — sisa scope Commission yang belum pernah dibangun: **Approval**
 * dan **Clawback**. Eligibility (Pending→Eligible) sudah di v0.9.5,
 * Payment (Eligible→Paid) di v0.9.11.
 *
 * ── APPROVAL (HANYA komisi Bulanan: recurring / limited_count) ──────────
 *
 * Komisi bulanan matang OTOMATIS dari invoice pelanggan lunas
 * (`CommissionLedgerMaturityService::matureForPaidInvoice()`) tanpa satu
 * pun manusia yang me-review. `approve()` menyisipkan gerbang manual di
 * antara: Eligible → **Approved** (baru bisa masuk payout) atau Eligible →
 * **Rejected** (dengan alasan, tidak akan pernah dibayar).
 *
 * **Titip TIDAK lewat approval sama sekali** — keputusan eksplisit v0.9.6:
 * entri Titip langsung `Eligible` begitu OTP WhatsApp ke Referrer
 * terverifikasi (OTP = jaring pengaman, bukan review admin). `approve()`/
 * `reject()` menolak baris scheme=titip secara eksplisit.
 *
 * ── CLAWBACK (SEMUA skema, termasuk Titip) ─────────────────────────────
 *
 * Pembatalan komisi manual (pelanggan batal / refund / koreksi kesalahan)
 * — berlaku untuk baris berstatus Eligible / Approved / **bahkan Paid**.
 * APPEND-ONLY: baris asli TIDAK diubah/dihapus; `clawback()` membuat
 * BARIS BARU (`amount` negatif, `status = Clawback`, `reversal_of_id`
 * menunjuk baris asli) — sama prinsip yang dipegang di seluruh codebase
 * (`reseller_tax_ledger`, `cpe_action_logs`, dst).
 *
 * Kalau baris yang di-clawback statusnya SUDAH `Paid` (uang sudah keluar),
 * catatan baris reversal ditandai `[UTANG]` — sistem ini TIDAK menarik
 * uang balik otomatis, cuma mencatat bahwa ada kewajiban tagih-balik ke
 * Referrer.
 */
class CommissionApprovalService
{
    /** Skema yang tunduk pada gerbang Approval. */
    private const MONTHLY_SCHEMES = [CommissionScheme::Recurring, CommissionScheme::LimitedCount];

    public function approve(CommissionLedger $entry, User $actor): CommissionLedger
    {
        $this->assertReviewable($entry);

        $entry->update([
            'status' => CommissionStatus::Approved,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        return $entry->fresh();
    }

    public function reject(CommissionLedger $entry, User $actor, string $reason): CommissionLedger
    {
        $this->assertReviewable($entry);

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Alasan penolakan wajib diisi.');
        }

        $trace = 'Ditolak '.now()->toDateTimeString()." oleh {$actor->name}: {$reason}";

        $entry->update([
            'status' => CommissionStatus::Rejected,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'notes' => $entry->notes ? $entry->notes."\n".$trace : $trace,
        ]);

        return $entry->fresh();
    }

    private function assertReviewable(CommissionLedger $entry): void
    {
        if (! in_array($entry->scheme, self::MONTHLY_SCHEMES, true)) {
            throw new RuntimeException(
                'Approval hanya untuk komisi bulanan (Per Bulan / X-Kali). Komisi Titip langsung layak dibayar setelah OTP terverifikasi.'
            );
        }

        if ($entry->status !== CommissionStatus::Eligible) {
            throw new RuntimeException('Hanya komisi berstatus "Layak Dibayar" yang bisa di-Approve atau Ditolak.');
        }
    }

    /**
     * Buat baris reversal untuk `$entry`. Mengembalikan baris BARU
     * (reversal), bukan baris asli — baris asli tidak disentuh.
     */
    public function clawback(CommissionLedger $entry, User $actor, string $reason): CommissionLedger
    {
        $this->assertClawbackable($entry);

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Alasan pembatalan wajib diisi.');
        }

        $wasPaid = $entry->status === CommissionStatus::Paid;

        $trace = "Clawback baris #{$entry->id} — ".now()->toDateTimeString()." oleh {$actor->name}: {$reason}";

        if ($wasPaid) {
            $trace .= "\n[UTANG] Komisi asli SUDAH DIBAYAR — perlu ditagih balik ke Referrer (pencatatan saja, sistem tidak menarik uang otomatis).";
        }

        return CommissionLedger::create([
            'tenant_id' => $entry->tenant_id,
            'referrer_id' => $entry->referrer_id,
            'customer_id' => $entry->customer_id,
            'invoice_id' => $entry->invoice_id,
            'reversal_of_id' => $entry->id,
            'scheme' => $entry->scheme,
            'payment_period' => $entry->payment_period,
            'amount' => -1 * abs((float) ($entry->amount ?? 0)),
            'gross_amount' => $entry->gross_amount !== null ? -1 * abs((float) $entry->gross_amount) : null,
            'status' => CommissionStatus::Clawback,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'notes' => $trace,
        ]);
    }

    private function assertClawbackable(CommissionLedger $entry): void
    {
        if ($entry->status === CommissionStatus::Clawback) {
            throw new RuntimeException('Baris ini sendiri adalah pembatalan (Clawback) — tidak bisa di-clawback lagi.');
        }

        if ($entry->status === CommissionStatus::Rejected) {
            throw new RuntimeException('Komisi yang sudah Ditolak tidak perlu dibatalkan lagi.');
        }

        if ($entry->status === CommissionStatus::Pending) {
            throw new RuntimeException('Komisi masih Pending (belum jadi kewajiban) — koreksi lewat atribusi pelanggan, bukan Clawback.');
        }

        if ($entry->wasClawedBack()) {
            throw new RuntimeException('Komisi ini sudah pernah dibatalkan (Clawback) sebelumnya.');
        }
    }
}
