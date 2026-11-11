<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.5.3.1 — jaring pengaman lapis-DB anti kirim-ganda untuk sweep
 * OTOMATIS: satu invoice hanya boleh punya SATU log `invoice_issued` NON-UJI
 * yang dibuat jalur OTOMATIS (`sent_by_user_id IS NULL`).
 *
 * SENGAJA di-scope `sent_by_user_id IS NULL` (bukan sekadar is_test=false):
 * kirim MANUAL admin (`sent_by_user_id` terisi) + fitur "Kirim Ulang"
 * (v0.13.5.3) memang boleh menghasilkan beberapa log non-uji untuk invoice
 * yang sama — index ini tidak boleh memblokirnya. Sweep otomatis selalu
 * `context: null` → `sent_by_user_id = null`, jadi persis jalur yang dijaga.
 * Dedup di command tetap mengecek SEMUA log non-uji (termasuk manual) → sweep
 * tak akan mengirim ulang invoice yang sudah pernah dikirim admin manual.
 * Log uji (`is_test=true`) tak dibatasi. Partial index: PostgreSQL + SQLite
 * (driver test) sama-sama mendukung.
 *
 * PRE-CHECK: tolak migrasi bila sudah ada duplikat (akan melanggar index).
 * Laporkan invoice_id yang bentrok supaya bisa dibersihkan dulu.
 */
return new class extends Migration
{
    private const INDEX_NAME = 'wa_logs_invoice_issued_nontest_unique';

    public function up(): void
    {
        $duplicates = DB::table('whatsapp_message_logs')
            ->selectRaw('invoice_id, count(*) as c')
            ->whereNotNull('invoice_id')
            ->where('event_type', 'invoice_issued')
            ->where('is_test', false)
            ->whereNull('sent_by_user_id')
            ->groupBy('invoice_id')
            ->havingRaw('count(*) > 1')
            ->pluck('c', 'invoice_id');

        if ($duplicates->isNotEmpty()) {
            $list = $duplicates->map(fn ($c, $id) => "invoice {$id}={$c}")->implode(', ');

            throw new RuntimeException(
                "Tidak bisa membuat unique index: ada log invoice_issued non-uji ganda ({$list}). ".
                'Bersihkan duplikat dulu lalu jalankan migrasi lagi.'
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEX_NAME.
                " ON whatsapp_message_logs (invoice_id) WHERE event_type = 'invoice_issued' AND is_test = false AND sent_by_user_id IS NULL AND invoice_id IS NOT NULL"
            );
        } else {
            // SQLite (test) — partial index syntax yang didukung.
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEX_NAME.
                " ON whatsapp_message_logs (invoice_id) WHERE event_type = 'invoice_issued' AND is_test = 0 AND sent_by_user_id IS NULL AND invoice_id IS NOT NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::table('whatsapp_message_logs', function () {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);
        });
    }
};
