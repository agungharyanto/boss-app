<?php

namespace App\Support;

/**
 * Revisi Prioritas Dropdown — RouterOS Queue Priority, dipakai bersama oleh
 * Profil Hotspot (v0.14.4) dan Profil PPP (v0.14.5). Range 1-8 dan default
 * 8 dikonfirmasi LANGSUNG terhadap `ro-hotspot.bajastu.id` (RouterOS
 * 7.12.1) sebelum kode ini ditulis — lihat migration
 * `2026_09_01_090000_change_priority_to_integer_on_hotspot_packages_table`
 * untuk detail verifikasi lengkap (pesan error `/queue/simple/add` sendiri
 * yang mengonfirmasi "1..8", dan readback `priority=8/8` pada baris
 * `/queue simple` baru yang genuinely tidak pernah di-set field
 * priority-nya sama sekali — itulah asal angka 8 sebagai default).
 *
 * `/ppp profile`/`/ip hotspot user profile` TIDAK punya parameter
 * `priority` berdiri sendiri (dikonfirmasi live, "unknown parameter
 * priority") — satu-satunya jalur push priority per-profil adalah lewat
 * slot ke-5 syntax `rate-limit` extended RouterOS:
 * `rx-rate/tx-rate rx-burst-rate/tx-burst-rate rx-burst-threshold/
 * tx-burst-threshold rx-burst-time/tx-burst-time priority`. `toRateLimitString()`
 * di bawah SELALU mengisi grup burst dengan nilai rate itu sendiri (bukan
 * angka bebas) — dikonfirmasi live bahwa `burst-rate=rate` membuat burst
 * genuinely inert (tidak ada headroom di atas rate untuk di-burst), jadi
 * hasil akhirnya identik secara fungsional dengan format lama yang polos
 * (`"{upload}k/{download}k"`, tanpa grup burst/priority sama sekali) selain
 * priority-nya sekarang eksplisit tertulis — bukan perilaku baru yang
 * berisiko, cuma menuliskan default RouterOS sendiri secara eksplisit.
 *
 * Slot priority embedded ini TIDAK divalidasi RouterOS sendiri (RouterOS
 * genuinely menerima nilai di luar 1-8 di posisi ini, beda dari field
 * `priority` berdiri sendiri di `/queue simple` yang menolak tegas) —
 * `MIN`/`MAX` di bawah karena itu SATU-SATUNYA penjaga range yang nyata,
 * bukan cuma kosmetik UI.
 */
class RouterOsQueuePriority
{
    public const MIN = 1;

    public const MAX = 8;

    /**
     * RouterOS's OWN genuine default when priority is never set at all —
     * see this class's own docblock for the live verification.
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
     * Builds the RouterOS extended `rate-limit` string carrying $priority
     * in its 5th positional slot — $uploadKbps/$downloadKbps in Kbps (this
     * codebase's own established BandwidthProfile storage unit), same
     * `"{n}k"` suffix convention already used by the plain 2-value format.
     */
    public static function toRateLimitString(int $uploadKbps, int $downloadKbps, int $priority): string
    {
        $rate = "{$uploadKbps}k/{$downloadKbps}k";

        return "{$rate} {$rate} {$rate} 1s/1s {$priority}";
    }
}
