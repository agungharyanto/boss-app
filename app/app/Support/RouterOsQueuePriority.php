<?php

namespace App\Support;

/**
 * Revisi Prioritas Dropdown — RouterOS Queue Priority, dipakai bersama oleh
 * Profil Hotspot (v0.14.4) dan Profil PPP (v0.14.5). Range 1-8 dan default
 * 8 dikonfirmasi LANGSUNG terhadap `ro-hotspot.bajastu.id` (RouterOS
 * 7.12.1) — pesan error `/queue/simple/add` sendiri yang mengonfirmasi
 * "1..8", dan readback `priority=8/8` pada baris `/queue simple` baru yang
 * genuinely tidak pernah di-set field priority-nya.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DARURAT 2026-09-06 — `toRateLimitString()` DULU selalu mengeluarkan grup
 * burst `"{rate} {rate} {rate} 1s/1s {priority}"`. RouterOS MENERIMA string
 * itu di `/ppp profile/set` DAN `/ip hotspot user profile/set` tanpa
 * protes — TAPI saat sebuah sesi PPPoE benar-benar connect, modul PPP
 * RouterOS menerjemahkan string itu jadi `/queue simple` dinamis dan GAGAL:
 * `could not add queue: no download-burst-time (6)` → sesi langsung
 * `terminating`. Di ro-hotspot.bajastu.id ini memutus ~200 sesi PPPoE
 * pelanggan aktif, log banjir, tiap koneksi baru langsung putus.
 *
 * Akar masalah: burst-time `1s/1s` bukan format yang sah untuk slot
 * burst-time di parser rate-limit RouterOS (butuh integer detik polos, dan
 * parser PPP→queue-nya lebih ketat dari parser `/queue/simple/add` biasa).
 * Grup burst itu sendiri juga sia-sia (`burst-rate = rate` = burst inert,
 * nol headroom) — jadi fix-nya: **JANGAN kirim grup burst sama sekali**,
 * kembali ke format polos `"{upload}k/{download}k"` yang sudah terbukti
 * bekerja untuk ratusan `/queue simple` dinamis di router yang sama.
 *
 * Konsekuensi: `priority` TIDAK LAGI di-push lewat `rate-limit` (secara
 * posisional, priority ada di slot ke-5 SETELAH grup burst — tidak bisa
 * dikirim tanpa mengisi slot 2-4 dulu). RouterOS default priority `/queue
 * simple` = 8 = `self::DEFAULT`, jadi untuk priority default nol yang
 * hilang; untuk priority non-default (1-7) nilainya tersimpan di DB tapi
 * tidak dikirim ke router (pola "stored, not pushed" yang sama dengan
 * `login_days`/`login_start_time` pada PppPackage). Mengembalikan push
 * priority butuh grup burst yang GENUINELY sah + terverifikasi terhadap
 * sesi PPPoE nyata — di luar scope perbaikan darurat ini.
 *
 * `composeRateLimit()` di bawah menyediakan jalur burst yang BENAR untuk
 * pemakaian di masa depan: grup burst HANYA disertakan kalau KETIGA
 * komponennya (burst-rate, burst-threshold, burst-time) lengkap; kalau satu
 * pun kosong → fallback ke format polos. Belum ada pemanggil yang mengirim
 * parameter burst (tidak ada field burst di form mana pun), jadi efeknya
 * saat ini identik dengan format polos.
 */
class RouterOsQueuePriority
{
    public const MIN = 1;

    public const MAX = 8;

    /**
     * RouterOS's OWN genuine default when priority is never set at all.
     */
    public const DEFAULT = 8;

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        $options = [];

        for ($i = self::MIN; $i <= self::MAX; $i++) {
            $options[$i] = match ($i) {
                self::MIN => "{$i} (Tertinggi)",
                self::MAX => "{$i} (Terendah — Default)",
                default => (string) $i,
            };
        }

        return $options;
    }

    /**
     * Plain RouterOS `rate-limit` = `rx-rate/tx-rate` only. Kbps in, `"{n}k"`
     * suffix out. $priority is accepted for signature stability but NOT
     * embedded — see this class's own docblock (DARURAT 2026-09-06).
     */
    public static function toRateLimitString(int $uploadKbps, int $downloadKbps, int $priority = self::DEFAULT): string
    {
        return self::composeRateLimit($uploadKbps, $downloadKbps);
    }

    /**
     * Full RouterOS extended `rate-limit` composer with all-or-nothing burst:
     *
     *   rx-rate/tx-rate [rx-burst-rate/tx-burst-rate rx-burst-threshold/
     *   tx-burst-threshold rx-burst-time/tx-burst-time [priority]]
     *
     * The burst group is emitted ONLY when burst-rate AND burst-threshold
     * AND burst-time are all provided (RouterOS rejects a partial burst
     * spec — "no download-burst-time"). Burst-time is a plain integer of
     * seconds (NEVER an "Ns" string — that is exactly what broke PPPoE on
     * 2026-09-06). Priority is only appended when a full burst group is
     * present (it is positionally after burst).
     */
    public static function composeRateLimit(
        int $uploadKbps,
        int $downloadKbps,
        ?int $burstUploadKbps = null,
        ?int $burstDownloadKbps = null,
        ?int $thresholdUploadKbps = null,
        ?int $thresholdDownloadKbps = null,
        ?int $burstTimeSeconds = null,
        ?int $priority = null,
    ): string {
        $rate = "{$uploadKbps}k/{$downloadKbps}k";

        $burstComplete = $burstUploadKbps !== null
            && $burstDownloadKbps !== null
            && $thresholdUploadKbps !== null
            && $thresholdDownloadKbps !== null
            && $burstTimeSeconds !== null;

        if (! $burstComplete) {
            return $rate;
        }

        $burst = "{$burstUploadKbps}k/{$burstDownloadKbps}k";
        $threshold = "{$thresholdUploadKbps}k/{$thresholdDownloadKbps}k";
        $time = "{$burstTimeSeconds}/{$burstTimeSeconds}";

        $string = "{$rate} {$burst} {$threshold} {$time}";

        if ($priority !== null) {
            $string .= " {$priority}";
        }

        return $string;
    }
}
