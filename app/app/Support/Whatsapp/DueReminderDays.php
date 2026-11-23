<?php

namespace App\Support\Whatsapp;

/**
 * v0.28.3 (T3, A5) — himpunan HARI pengingat jatuh tempo (H-0..H-7).
 *
 * Satu sumber kebenaran aturan validasi + sanitasi, dipakai bareng oleh
 * CustomerAutoSendOrchestrator (sanitasi nilai tersimpan tiap tick) dan UI
 * pengaturan v0.28.5 (rules()). Nilai disimpan di
 * work_order_dispatch_settings.due_reminder_days (cast array).
 *
 * Aturan (keputusan Agung 2026-11-23): daftar bilangan bulat, minimal 1 bila
 * diisi, tiap nilai 0..7 (H-0..H-7), tolak kosong/8/-1/non-int. Default
 * `[5, 0]` = perilaku lama (H-5 dan H-0).
 */
final class DueReminderDays
{
    /** @var list<int> perilaku lama: H-5 dan H-0. */
    public const DEFAULT = [5, 0];

    public const MIN = 0;

    public const MAX = 7;

    /**
     * true hanya bila $days adalah list non-kosong berisi bilangan bulat
     * (atau string digit murni) yang SEMUANYA berada di rentang 0..7. Dipakai
     * validasi UI + test; sanitize() memakai logika yang sama.
     */
    public static function isValid(mixed $days): bool
    {
        if (! is_array($days) || $days === []) {
            return false;
        }

        foreach ($days as $day) {
            if (! self::isWholeDayInRange($day)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Kembalikan list hari yang SAH + ter-dedup (urutan sumber dipertahankan).
     * Bila nilai tersimpan tidak sah/kosong/null → DEFAULT ([5, 0]) — jaring
     * pengaman supaya konfigurasi rusak tidak pernah mengirim pada hari di luar
     * rentang atau diam-diam berhenti mengirim.
     *
     * @return list<int>
     */
    public static function sanitize(mixed $days): array
    {
        if (! self::isValid($days)) {
            return self::DEFAULT;
        }

        $out = [];
        foreach ($days as $day) {
            $int = (int) $day;
            if (! in_array($int, $out, true)) {
                $out[] = $int;
            }
        }

        return $out;
    }

    /**
     * Aturan Laravel untuk form pengaturan (v0.28.5). Minimal 1 hari bila field
     * diisi; tiap elemen integer 0..7. Field sendiri boleh tidak dikirim
     * (sometimes) — pemanggil memutuskan required/tidak.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'due_reminder_days' => ['array', 'min:1'],
            'due_reminder_days.*' => ['integer', 'min:'.self::MIN, 'max:'.self::MAX],
        ];
    }

    private static function isWholeDayInRange(mixed $day): bool
    {
        // Terima int atau string digit murni (mis. dari payload JSON/form);
        // tolak float non-bulat, boolean, null, string non-digit.
        if (is_int($day)) {
            $int = $day;
        } elseif (is_string($day) && $day !== '' && ctype_digit($day)) {
            $int = (int) $day;
        } else {
            return false;
        }

        return $int >= self::MIN && $int <= self::MAX;
    }
}
