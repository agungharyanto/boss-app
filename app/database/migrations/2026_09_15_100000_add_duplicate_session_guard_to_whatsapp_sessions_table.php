<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cegah 1 nomor WA fisik dipakai di lebih dari 1 session BOSS App sekaligus
 * (ditemukan lewat verifikasi manual — Agung sempat pairing nomor yang sama
 * ke session "direct" DAN session reseller, WhatsApp memperlakukan itu
 * sebagai 2 linked device dari akun yang sama, delivery pesan masuk jadi
 * non-deterministik, merusak isolasi reseller_id). Lihat
 * App\Services\Whatsapp\WhatsappSessionService::applyStatus() untuk logic
 * aplikasi (deteksi + tolak + logout paksa + notifikasi WA) — migration ini
 * cuma menyiapkan tempatnya.
 *
 * `status_reason` — nullable, dipakai HANYA untuk status
 * `rejected_duplicate` saat ini (menjelaskan KENAPA session ditolak,
 * bukan cuma label status pendek "Ditolak") — kolom generik, bisa dipakai
 * status lain di masa depan kalau perlu.
 *
 * Partial unique index `(phone_number) WHERE status = 'connected'` —
 * SAFETY NET race condition di level DB, BUKAN mekanisme aplikasi utama
 * (yang tetap SELECT-check dulu di WhatsappSessionService untuk
 * memberikan pesan error yang jelas di kasus normal). Portable ke SQLite
 * (test driver) juga — DB::statement() raw SQL yang sama persis, sama
 * pola yang sudah dipakai migration create_whatsapp_sessions_table untuk
 * whatsapp_sessions_reseller_unique/whatsapp_sessions_direct_unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->text('status_reason')->nullable()->after('status');
        });

        DB::statement(
            'CREATE UNIQUE INDEX whatsapp_sessions_connected_phone_unique ON whatsapp_sessions (phone_number) WHERE status = \'connected\''
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS whatsapp_sessions_connected_phone_unique');

        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->dropColumn('status_reason');
        });
    }
};
