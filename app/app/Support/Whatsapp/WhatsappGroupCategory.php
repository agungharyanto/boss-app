<?php

namespace App\Support\Whatsapp;

use App\Enums\WhatsappEventType;

/**
 * v0.13.4.4 — daftar KATEGORI notifikasi yang bisa dirutekan ke grup WA,
 * beserta label Indonesia untuk UI Routing. Key kategori = nilai
 * WhatsappEventType (string) supaya resolver tinggal mencocokkan event type
 * pesan grup ke kategori tanpa pemetaan terpisah.
 *
 * MENAMBAH KATEGORI BARU = tambah satu baris di labels() di bawah (dengan
 * event type yang genuinely dikirim ke grup) — TANPA migrasi DB (tabel
 * whatsapp_group_routes menyimpan `category` sebagai string bebas).
 *
 * Saat ini hanya 3 event type yang genuinely keluar ke grup (semuanya Work
 * Order — lihat WorkOrderDispatchService); event type lain (invoice,
 * payment, OTP, dll) japri ke pelanggan/teknisi, bukan grup, jadi TIDAK
 * didaftar di sini.
 */
final class WhatsappGroupCategory
{
    /**
     * @return array<string, string> category (event_type value) => label Indonesia
     */
    public static function labels(): array
    {
        return [
            WhatsappEventType::WorkOrderDispatched->value => 'WO Baru / Dispatch & Reminder',
            WhatsappEventType::WorkOrderClaimedReminder->value => 'Reminder WO Sudah Diklaim',
            WhatsappEventType::WorkOrderCompleted->value => 'WO Selesai (+ Foto)',
        ];
    }

    /**
     * @return list<string> daftar kategori (nilai event_type) yang valid dirutekan
     */
    public static function all(): array
    {
        return array_keys(self::labels());
    }

    public static function label(string $category): ?string
    {
        return self::labels()[$category] ?? null;
    }
}
