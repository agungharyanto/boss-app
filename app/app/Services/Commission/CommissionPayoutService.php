<?php

namespace App\Services\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\TitipDepositStatus;
use App\Models\CommissionLedger;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * v0.9.11 — bagian "Payment" dari scope Commission (v0.9.0) yang belum
 * pernah dibangun (v0.9.5 cuma menangani Eligibility, Pending→Eligible,
 * bukan pembayaran aktualnya). Dua mekanisme BERBEDA, sengaja tidak
 * disatukan jadi satu method generik "bayar komisi":
 *
 * 1. TITIP — instan, kapan saja, TAPI wajib `deposit_status = SudahSetor`
 *    lebih dulu (guard keras: tidak bisa bayar komisi sebelum uang cash-
 *    nya sendiri confirmed masuk dari Referrer). Wajib upload bukti bayar
 *    (foto transfer/cash) per transaksi pembayaran.
 * 2. BULANAN (recurring/limited_count) — batch per Referrer, jendela
 *    tanggal payout DIKONFIGURASI PER `CommissionRate` (bukan satu aturan
 *    global — lihat amandemen di bawah), guard di SERVER
 *    (`isRowPayableNow()`), bukan cuma UI. Tidak mensyaratkan bukti bayar
 *    (beda dari Titip — lihat migration 2026_09_05_090000 untuk alasan
 *    `payment_proof_path` nullable).
 *
 * Payout Titip mentransisikan `Eligible` → `Paid`. Payout Bulanan
 * mentransisikan **`Approved`** → `Paid` — sejak v0.9.0, komisi bulanan
 * WAJIB di-Approve admin dulu (`CommissionApprovalService`) sebelum bisa
 * dibayar; baris `Eligible` yang belum di-Approve TIDAK ikut ke payout.
 * Titip tetap TIDAK lewat approval (OTP WhatsApp jadi gerbangnya, lihat
 * keputusan v0.9.6). Bukan kolom boolean `is_paid` terpisah — reuse
 * `status` enum yang sudah ada sejak v0.3.0.
 *
 * AMANDEMEN (2026-09-05) — "tanggal 5-7" TIDAK LAGI hardcode global.
 * Sebelumnya `PAYOUT_WINDOW_START_DAY`/`_END_DAY`/`isWithinMonthlyPayoutWindow()`
 * ada di sini sebagai konstanta level-service, berlaku untuk SEMUA Referrer
 * tanpa kecuali. Digeneralisasi jadi `commission_rates.payout_window_start_day`/
 * `_end_day` (nullable, per paket) — NULL/NULL = kapan saja (default,
 * termasuk untuk paket yang belum pernah diatur admin), diisi = dibatasi ke
 * rentang itu. Konsekuensi nyata: satu Referrer bisa punya baris komisi
 * dari BEBERAPA paket dengan jendela BERBEDA-beda sekaligus — `isRowPayableNow()`
 * mengecek TIAP baris independen lewat rate paket customer-nya masing-
 * masing, `payMonthlyForReferrer()` hanya membayar baris yang genuinely
 * payable SEKARANG dan diam-diam melewati sisanya (sama semantik "bayar
 * yang bisa, skip yang belum" seperti `payTitipForReferrer()` — BUKAN lagi
 * "tolak seluruh batch kalau ada satu saja di luar jendela", yang tidak
 * masuk akal lagi begitu tiap baris bisa punya aturan sendiri-sendiri).
 */
class CommissionPayoutService
{
    /**
     * Bayar SATU baris komisi Titip. Melempar RuntimeException (pesan
     * user-facing Indonesia) kalau baris tidak memenuhi syarat — caller
     * (Livewire) menangkapnya dan menampilkan sebagai error, bukan crash.
     */
    public function payTitipRow(CommissionLedger $entry, User $actor, UploadedFile $proof): CommissionLedger
    {
        $this->assertTitipPayable($entry);

        $path = Storage::disk('local')->putFile('commission-payment-proofs', $proof);

        $entry->update([
            'status' => CommissionStatus::Paid,
            'paid_at' => now(),
            'paid_by' => $actor->id,
            'payment_proof_path' => $path,
        ]);

        return $entry->fresh();
    }

    /**
     * Bayar SEMUA baris Titip milik satu Referrer yang genuinely memenuhi
     * syarat saat ini (Eligible + SudahSetor) — satu bukti bayar berlaku
     * untuk seluruh batch ini (satu transfer/serahan cash bisa menutup
     * beberapa transaksi titip sekaligus). Baris yang TIDAK memenuhi
     * syarat diam-diam dilewati (bukan error) — tombol ini punya makna
     * "bayar semua yang BISA dibayar sekarang", bukan "harus semuanya".
     *
     * @return int jumlah baris yang benar-benar dibayar
     */
    public function payTitipForReferrer(int $referrerId, User $actor, UploadedFile $proof): int
    {
        $rows = CommissionLedger::query()
            ->where('scheme', CommissionScheme::Titip->value)
            ->where('referrer_id', $referrerId)
            ->where('status', CommissionStatus::Eligible->value)
            ->where('deposit_status', TitipDepositStatus::SudahSetor->value)
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $path = Storage::disk('local')->putFile('commission-payment-proofs', $proof);

        CommissionLedger::query()
            ->whereIn('id', $rows->pluck('id'))
            ->update([
                'status' => CommissionStatus::Paid->value,
                'paid_at' => now(),
                'paid_by' => $actor->id,
                'payment_proof_path' => $path,
            ]);

        return $rows->count();
    }

    private function assertTitipPayable(CommissionLedger $entry): void
    {
        if ($entry->scheme !== CommissionScheme::Titip) {
            throw new RuntimeException('Aksi ini hanya berlaku untuk komisi skema Titip.');
        }

        if ($entry->status === CommissionStatus::Paid) {
            throw new RuntimeException('Komisi ini sudah pernah dibayar sebelumnya.');
        }

        if ($entry->status !== CommissionStatus::Eligible) {
            throw new RuntimeException('Komisi ini belum berstatus "Layak Dibayar".');
        }

        if ($entry->deposit_status !== TitipDepositStatus::SudahSetor) {
            throw new RuntimeException(
                'Setoran uang titip dari Referrer belum ditandai "Sudah Setor" — komisi tidak bisa dibayar sebelum setorannya diterima.'
            );
        }
    }

    /**
     * Apakah SATU baris komisi bulanan (recurring/limited_count) bisa
     * dibayar SEKARANG — dibaca dari jendela tanggal `CommissionRate`
     * milik PAKET customer baris ini, BUKAN aturan global. Rate diresolve
     * LIVE (`customer->pppPackage->commissionRate`), bukan snapshot yang
     * disimpan di baris ledger itu sendiri — konsisten dengan cara
     * `amount` juga selalu di-refresh dari rate saat ini (v0.9.5), BUKAN
     * dikunci ke rate yang berlaku saat baris ini pertama matang.
     *
     * Tidak bisa diresolve sama sekali (customer tanpa paket, paket tanpa
     * rate aktif) → diperlakukan SAMA seperti rate tanpa jendela sama
     * sekali (payable kapan saja) — konsisten dengan makna NULL/NULL yang
     * sudah didefinisikan di `CommissionRate::hasPayoutWindow()`, bukan
     * kasus khusus terpisah.
     *
     * `?Carbon $now` opsional, dipakai test untuk mem-mock server time
     * (`Carbon::setTestNow(...)`) — pemanggil produksi selalu memanggil
     * tanpa argumen (server time asli).
     */
    public function isRowPayableNow(CommissionLedger $entry, ?Carbon $now = null): bool
    {
        $rate = $entry->customer?->pppPackage?->commissionRate;

        if ($rate === null) {
            return true;
        }

        return $rate->isWithinPayoutWindow($now);
    }

    /**
     * Bayar SEMUA baris komisi bulanan (recurring/limited_count) milik
     * satu Referrer yang berstatus **Approved** DAN genuinely payable
     * sekarang (`isRowPayableNow()`, per rate paketnya masing-masing) —
     * batch, bukan satu-satu. Baris Approved tapi jendela paketnya sedang
     * TERTUTUP diam-diam dilewati, sama semantik
     * `payTitipForReferrer()` — bukan lagi menolak seluruh panggilan
     * (lihat docblock class untuk alasan perubahan ini).
     *
     * @return int jumlah baris yang benar-benar dibayar
     */
    /**
     * Bayar SATU baris komisi bulanan (recurring/limited_count).
     * Bagian D (v0.9.12) — dipakai halaman "Fee Komisi" yang kini gabungan
     * Titip + Bulanan; tombol bayar per baris hanya muncul kalau
     * `isRowPayableNow()` true (jendela paketnya terbuka). Guard di sini
     * juga, bukan cuma UI. Tidak butuh bukti bayar (sama seperti
     * `payMonthlyForReferrer()`).
     *
     * @throws RuntimeException pesan user-facing Indonesia kalau tidak layak
     */
    public function payMonthlyRow(CommissionLedger $entry, User $actor): CommissionLedger
    {
        if (! in_array($entry->scheme, [CommissionScheme::Recurring, CommissionScheme::LimitedCount], true)) {
            throw new RuntimeException('Aksi ini hanya berlaku untuk komisi bulanan (Per Bulan / X-Kali).');
        }

        if ($entry->status === CommissionStatus::Paid) {
            throw new RuntimeException('Komisi ini sudah pernah dibayar sebelumnya.');
        }

        if ($entry->status === CommissionStatus::Eligible) {
            throw new RuntimeException('Komisi bulanan harus di-Approve admin dulu di halaman Fee Komisi sebelum bisa dibayar.');
        }

        if ($entry->status !== CommissionStatus::Approved) {
            throw new RuntimeException('Hanya komisi bulanan berstatus "Disetujui" yang bisa dibayar.');
        }

        if (! $this->isRowPayableNow($entry)) {
            throw new RuntimeException('Jendela payout untuk paket komisi ini sedang tertutup — coba lagi dalam rentang tanggal yang ditetapkan pada Rate Komisi.');
        }

        $entry->update([
            'status' => CommissionStatus::Paid,
            'paid_at' => now(),
            'paid_by' => $actor->id,
        ]);

        return $entry->fresh();
    }

    public function payMonthlyForReferrer(int $referrerId, User $actor): int
    {
        $rows = CommissionLedger::query()
            ->whereIn('scheme', [CommissionScheme::Recurring->value, CommissionScheme::LimitedCount->value])
            ->where('referrer_id', $referrerId)
            ->where('status', CommissionStatus::Approved->value)
            ->with('customer.pppPackage.commissionRate')
            ->get();

        $payableIds = $rows->filter(fn (CommissionLedger $row) => $this->isRowPayableNow($row))
            ->pluck('id');

        if ($payableIds->isEmpty()) {
            return 0;
        }

        CommissionLedger::query()
            ->whereIn('id', $payableIds)
            ->update([
                'status' => CommissionStatus::Paid->value,
                'paid_at' => now(),
                'paid_by' => $actor->id,
            ]);

        return $payableIds->count();
    }
}
