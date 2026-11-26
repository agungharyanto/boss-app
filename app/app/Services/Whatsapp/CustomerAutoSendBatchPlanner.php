<?php

namespace App\Services\Whatsapp;

use App\Models\CustomerAutoSendCursor;
use App\Models\WhatsappGatewaySettings;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * v0.28.4 (SEBAR-BATCH) — perencana jeda-batch kirim otomatis pelanggan (A4).
 *
 * Satu kursor per (tenant, session_key) yang BERTAHAN lintas tick everyMinute
 * (D2): `next_slot_at` = waktu dinding slot berikutnya, `batch_count` = posisi
 * dalam batch. reserveSlot() dipanggil GERBANG HANYA pada jalur antre-sungguhan
 * (setelah semua cek skip + Mode Uji), jadi slot dikonsumsi HANYA untuk kirim
 * nyata — Simulated/Skipped/Deferred tak pernah memajukan kursor.
 *
 * Jeda ANTAR-batch disuntik lewat ->delay() saat dispatch (D1); jarak di DALAM
 * satu batch dibiarkan ke sleep 5–10 dtk worker (FIFO, 1 worker) — kolom
 * rate_limit_delay_max dipakai HANYA untuk akuntansi slot (memperkirakan kapan
 * batch penuh menyentuh akhir jendela), bukan sleep tambahan di job.
 *
 * Batas jendela (D3): bila slot melewati akhir jendela hari itu → reserveSlot()
 * mengembalikan null (kursor TIDAK berubah); gerbang memperlakukannya seperti
 * OutsideWindow (tick-stop; slot monoton naik → kandidat berikutnya pasti juga
 * lewat). Update kursor atomik: DB::transaction + lockForUpdate.
 */
class CustomerAutoSendBatchPlanner
{
    public const TIMEZONE = 'Asia/Jakarta';

    /**
     * Pesan non-pertama dalam batch di-dispatch delay≈0 (worker yang memberi
     * jarak). Fallback bila kolom rate_limit tak masuk akal.
     */
    public const DEFAULT_INTRA_STEP_SECONDS = 10;

    /**
     * Cadangkan slot untuk SATU pesan otomatis pada (tenant, sesi). Return slot
     * (CarbonImmutable, WIB) bila boleh antre — pemanggil menghitung
     * delay = slot - now; atau null bila slot melewati akhir jendela (D3,
     * kursor tak berubah).
     */
    public function reserveSlot(int $tenantId, ?int $resellerId, WorkOrderDispatchSettings $settings): ?CarbonImmutable
    {
        $sessionKey = WhatsappSession::sessionKeyFor($resellerId);
        $gw = WhatsappGatewaySettings::current();

        $batchSize = max(1, (int) $gw->rate_limit_batch_size);
        $pauseMin = max(1, (int) $gw->rate_limit_batch_pause_min_minutes);
        $pauseMax = max($pauseMin, (int) $gw->rate_limit_batch_pause_max_minutes);
        $intraStep = max(1, (int) $gw->rate_limit_delay_max_seconds ?: self::DEFAULT_INTRA_STEP_SECONDS);

        // $now = instant sekarang (zona app, hormati setTestNow). getTimestamp()
        // absolut → perbandingan/selisih tak bergantung representasi zona.
        $now = CarbonImmutable::now();
        $windowEnd = $this->windowEnd($settings, $now);

        return DB::transaction(function () use ($tenantId, $sessionKey, $now, $windowEnd, $batchSize, $pauseMin, $pauseMax, $intraStep) {
            $cursor = CustomerAutoSendCursor::query()
                ->where('tenant_id', $tenantId)
                ->where('session_key', $sessionKey)
                ->lockForUpdate()
                ->first();

            if ($cursor === null) {
                $cursor = CustomerAutoSendCursor::create([
                    'tenant_id' => $tenantId,
                    'session_key' => $sessionKey,
                ]);
            }

            // Reset bila null / lewat (pacing sudah menyusul): slot = now, mulai
            // batch baru. Selain itu lanjut dari slot & posisi tersimpan (D2).
            // createFromInterface() menyalin INSTAN apa adanya (BUKAN parse+tz,
            // yang akan me-reinterpret wall-clock dan menggeser epoch).
            $planned = $cursor->next_slot_at !== null
                ? CarbonImmutable::createFromInterface($cursor->next_slot_at)
                : null;

            if ($planned === null || $planned->lessThanOrEqualTo($now)) {
                $slot = $now;
                $batchCount = 0;
            } else {
                $slot = $planned;
                $batchCount = (int) $cursor->batch_count;
            }

            // D3 — slot melewati akhir jendela: jangan antre, kursor TIDAK berubah.
            if ($slot->greaterThan($windowEnd)) {
                return null;
            }

            // Majukan kursor untuk pesan BERIKUTNYA.
            $batchCount++;
            if ($batchCount >= $batchSize) {
                $nextSlot = $slot->addMinutes(random_int($pauseMin, $pauseMax));
                $batchCount = 0;
            } else {
                $nextSlot = $slot->addSeconds($intraStep);
            }

            $cursor->update([
                'next_slot_at' => $nextSlot,
                'batch_count' => $batchCount,
            ]);

            return $slot;
        });
    }

    private function windowEnd(WorkOrderDispatchSettings $settings, CarbonImmutable $now): CarbonImmutable
    {
        $end = $settings->customer_auto_send_window_end ?? CustomerAutoSendGate::DEFAULT_WINDOW_END;
        [$h, $m] = array_map('intval', explode(':', $end));

        // Jam jendela adalah WIB — hitung instan akhir jendela di WIB (hari ini),
        // apa pun zona app. Perbandingan slot>windowEnd bersifat absolut (instan).
        return $now->setTimezone(self::TIMEZONE)->setTime($h, $m, 0);
    }
}
