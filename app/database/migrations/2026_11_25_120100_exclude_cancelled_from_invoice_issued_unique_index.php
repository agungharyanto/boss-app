<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v0.28.4 (SEBAR-BATCH, D5) — index unik parsial anti kirim-ganda invoice_issued
 * (v0.13.5.3.1) TIDAK mengecualikan status. Sejak v0.28.4 sebuah log otomatis
 * bisa berakhir `cancelled` (recheck D4 / pembersih A3), dan D5 menuntut
 * kandidatnya BISA dikumpulkan ulang orkestrator → insert log invoice_issued
 * BARU untuk invoice yang sama. Dengan predikat lama, baris `cancelled` masih
 * menduduki slot unik → insert ulang MELANGGAR constraint. Tambah
 * `AND status <> 'cancelled'` ke predikat: baris batal tak lagi menduduki slot,
 * baris aktif (queued/sent/failed/delivered) tetap dijaga tunggal per invoice.
 *
 * Buat index BARU (bukan edit migrasi v0.13.5.3.1 yang sudah ter-tag).
 */
return new class extends Migration
{
    private const INDEX_NAME = 'wa_logs_invoice_issued_nontest_unique';

    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEX_NAME.
                " ON whatsapp_message_logs (invoice_id) WHERE event_type = 'invoice_issued' AND is_test = false AND sent_by_user_id IS NULL AND invoice_id IS NOT NULL AND status <> 'cancelled'"
            );
        } else {
            // SQLite (driver test) — predikat partial index yang didukung.
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEX_NAME.
                " ON whatsapp_message_logs (invoice_id) WHERE event_type = 'invoice_issued' AND is_test = 0 AND sent_by_user_id IS NULL AND invoice_id IS NOT NULL AND status <> 'cancelled'"
            );
        }
    }

    public function down(): void
    {
        // Pulihkan ke predikat v0.13.5.3.1 (tanpa klausa status).
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEX_NAME.
                " ON whatsapp_message_logs (invoice_id) WHERE event_type = 'invoice_issued' AND is_test = false AND sent_by_user_id IS NULL AND invoice_id IS NOT NULL"
            );
        } else {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEX_NAME.
                " ON whatsapp_message_logs (invoice_id) WHERE event_type = 'invoice_issued' AND is_test = 0 AND sent_by_user_id IS NULL AND invoice_id IS NOT NULL"
            );
        }
    }
};
