<?php

namespace App\Enums;

/**
 * v0.28.2 (T2) — hasil beralasan gerbang kirim otomatis (CustomerAutoSendGate).
 * Menggantikan `null` telanjang dari buildAndQueue (yang dulu ambigu: template
 * nonaktif / saklar OFF / opt-out semuanya null — lihat catatan T2 ROADMAP).
 */
enum CustomerAutoSendResult: string
{
    case Queued = 'queued';                               // benar-benar diantre (ada log + job)
    case SkippedAutoNotifyOff = 'skipped_auto_notify_off'; // saklar tenant OFF (TIDAK dicatat)
    case SkippedOptOut = 'skipped_opt_out';               // pelanggan opt-out (v0.28.1)
    case SkippedTemplateInactive = 'skipped_template_inactive';
    case SkippedTestMode = 'skipped_test_mode';           // invoice_issued saat Mode Uji = berhenti total (K9a)
    case Simulated = 'simulated';                         // Mode Uji: due/suspend/registered dicatat "akan dikirim" (K9)
    case Deferred = 'deferred';                           // customer_registered ditunda (K11)
    case Expired = 'expired';                             // deferral customer_registered kadaluwarsa > 48 jam (v0.28.3, dicatat)
    case OutsideWindow = 'outside_window';                // di luar jendela jam (K3)
    case SessionDown = 'session_down';                    // sesi WA tidak Connected (K12)
    case BreakerOpen = 'breaker_open';                    // N kegagalan beruntun (K12)
    case CapReached = 'cap_reached';                      // plafon harian gabungan (K2/K10)

    /**
     * Hasil level-RUN: jalur terjadwal (sweep/reminder) menghentikan seluruh
     * run saat menemui ini (bukan hanya melewati satu pelanggan).
     */
    public function isRunStop(): bool
    {
        return in_array($this, [
            self::OutsideWindow,
            self::CapReached,
            self::SessionDown,
            self::BreakerOpen,
            self::SkippedTestMode,
        ], true);
    }

    /**
     * Alasan level-run dicatat PALING BANYAK sekali per jalur per hari (WIB)
     * agar tidak membanjiri tabel keputusan (aturan tambahan 2 Agung).
     */
    public function recordedOncePerDay(): bool
    {
        return $this->isRunStop();
    }

    /**
     * SkippedAutoNotifyOff & Queued tidak dicatat ke customer_auto_send_decisions
     * (noise / sudah ada di whatsapp_message_logs).
     */
    public function isRecorded(): bool
    {
        return ! in_array($this, [self::SkippedAutoNotifyOff, self::Queued], true);
    }
}
