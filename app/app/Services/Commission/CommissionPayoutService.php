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
 * 2. BULANAN (recurring/limited_count) — batch per Referrer, HANYA bisa
 *    diproses tanggal 5-7 bulan berjalan (guard di SERVER, method ini
 *    sendiri yang menolak — bukan cuma disembunyikan di UI, supaya tidak
 *    bisa di-bypass lewat panggilan langsung ke Livewire/endpoint di luar
 *    jendela itu). Tidak mensyaratkan bukti bayar (beda dari Titip — lihat
 *    migration 2026_09_05_090000 untuk alasan `payment_proof_path`
 *    nullable).
 *
 * Baik payout Titip maupun bulanan MENTRANSISIKAN `status` yang sudah ada
 * (`Eligible` → `Paid`, case yang sudah ada sejak v0.3.0 tapi baru
 * benar-benar dipakai di sini) — bukan kolom boolean `is_paid` terpisah
 * yang redundan.
 */
class CommissionPayoutService
{
    public const PAYOUT_WINDOW_START_DAY = 5;

    public const PAYOUT_WINDOW_END_DAY = 7;

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
     * Tanggal 5-7 bulan berjalan adalah SATU-SATUNYA jendela waktu payout
     * komisi bulanan boleh diproses. `?Carbon $now` opsional, dipakai test
     * untuk mem-mock server time (`Carbon::setTestNow(...)`) — pemanggil
     * produksi selalu memanggil tanpa argumen (server time asli).
     */
    public function isWithinMonthlyPayoutWindow(?Carbon $now = null): bool
    {
        $day = ($now ?? now())->day;

        return $day >= self::PAYOUT_WINDOW_START_DAY && $day <= self::PAYOUT_WINDOW_END_DAY;
    }

    /**
     * Bayar SEMUA baris komisi bulanan (recurring/limited_count) milik
     * satu Referrer yang berstatus Eligible — batch, bukan satu-satu.
     * GUARD KERAS di sini, bukan cuma di Livewire — pemanggil apa pun
     * (UI, direct method call, endpoint API kalau nanti ada) tunduk pada
     * jendela tanggal yang sama, tidak ada jalur bypass.
     *
     * @return int jumlah baris yang dibayar
     *
     * @throws RuntimeException kalau dipanggil di luar tanggal 5-7
     */
    public function payMonthlyForReferrer(int $referrerId, User $actor): int
    {
        if (! $this->isWithinMonthlyPayoutWindow()) {
            throw new RuntimeException(
                'Payout komisi bulanan hanya bisa diproses tanggal 5-7 setiap bulan. Buka lagi nanti.'
            );
        }

        return CommissionLedger::query()
            ->whereIn('scheme', [CommissionScheme::Recurring->value, CommissionScheme::LimitedCount->value])
            ->where('referrer_id', $referrerId)
            ->where('status', CommissionStatus::Eligible->value)
            ->update([
                'status' => CommissionStatus::Paid->value,
                'paid_at' => now(),
                'paid_by' => $actor->id,
            ]);
    }
}
