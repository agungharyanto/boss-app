<?php

use App\Models\WhatsappIncomingMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.1 perluasan — scoping reseller untuk "Pesan Masuk". Migration
 * TERPISAH dari `2026_09_14_100000_create_whatsapp_incoming_messages_table`
 * (bukan edit ulang) — tabel itu sudah genuinely `migrate` di DB dev dengan
 * baris nyata hasil test WA manual (8 baris per penulisan migration ini),
 * mengedit migration lama berarti rollback = kehilangan data itu.
 *
 * Pola nullable FK POLOS ala `whatsapp_message_logs.reseller_id` — BUKAN
 * pola partial-unique-index `reseller_tax_policies` (dikonfirmasi tidak
 * butuh uniqueness apa pun, banyak baris boleh sama-sama satu reseller).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_incoming_messages', function (Blueprint $table) {
            $table->foreignId('reseller_id')->nullable()->after('session_key')->constrained('resellers')->nullOnDelete();
        });

        // Backfill baris existing — session_key numerik (reseller) ->
        // reseller_id itu; session_key literal 'direct' (atau apa pun yang
        // bukan digit murni) -> tetap NULL (default kolom, tidak perlu
        // di-touch). Filter "apakah numerik" dilakukan di PHP (ctype_digit),
        // BUKAN raw SQL regex (`~` operator Postgres, tidak portable ke
        // SQLite yang dipakai test suite — gotcha driver yang sudah
        // berulang kali dicatat di CLAUDE.md). Query generik, bukan "tahu
        // semua baris sekarang 'direct'" — benar untuk deployment mana pun
        // yang menjalankan migration ini nanti, bukan cuma DB dev saat ini.
        WhatsappIncomingMessage::query()
            ->where('session_key', '!=', 'direct')
            ->get()
            ->filter(fn (WhatsappIncomingMessage $message) => ctype_digit($message->session_key))
            ->each(function (WhatsappIncomingMessage $message) {
                $message->update(['reseller_id' => (int) $message->session_key]);
            });
    }

    public function down(): void
    {
        Schema::table('whatsapp_incoming_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reseller_id');
        });
    }
};
