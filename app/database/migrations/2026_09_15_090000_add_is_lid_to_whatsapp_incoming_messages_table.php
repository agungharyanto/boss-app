<?php

use App\Models\WhatsappIncomingMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix bug LID (investigasi + keputusan Agung 2026-09-15) — sender_phone
 * kadang tersimpan sebagai raw WhatsApp LID (Linked ID, mis.
 * "44435932971043"), bukan nomor HP asli. Lihat internal/session/
 * manager.go::resolveSenderPhone() (whatsapp-gateway) untuk fallback a-d
 * yang menutup akar masalahnya di sisi Go — migration ini cuma menambah
 * kolom penanda + backfill baris yang SUDAH tersimpan salah sebelum fix itu
 * ada. Migration TERPISAH dari 2 migration whatsapp_incoming_messages
 * sebelumnya (bukan edit ulang) — tabel sudah genuinely `migrate` di DB dev
 * dengan baris nyata hasil test WA manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_incoming_messages', function (Blueprint $table) {
            $table->boolean('is_lid')->default(false)->after('sender_phone');
        });

        // Backfill baris existing — DETEKSI PASTI dari chat_jid yang SUDAH
        // tersimpan, bukan heuristik panjang digit (yang cuma perkiraan,
        // sempat disarankan sebelum kolom ini dibaca ulang): untuk chat
        // 1-on-1 (bukan grup, satu-satunya jenis pesan yang pernah ditangkap
        // onIncomingMessage()), chat_jid == JID pengirim itu sendiri — jadi
        // suffix "@lid" di chat_jid adalah bukti LANGSUNG bahwa
        // sender_phone baris itu raw LID, bukan tebakan dari panjang angka
        // (nomor Indonesia format 62xxx bisa sampai 13 digit, cukup dekat
        // dengan LID 14-15 digit yang teramati di baris nyata — deteksi
        // berbasis chat_jid ini tidak punya ambiguitas semacam itu).
        // Dikonfirmasi langsung terhadap DB dev sebelum menulis migration
        // ini: 15 dari 16 baris existing chat_jid-nya berakhiran "@lid",
        // 1 baris (sender_phone 081200000099) berakhiran "@s.whatsapp.net"
        // — bukan cuma 3 nilai yang sempat disebut di percakapan
        // sebelumnya (44435932971043/221032002642155/106141577146396) hari
        // ini sudah lebih banyak karena testing lanjutan.
        WhatsappIncomingMessage::query()
            ->where('chat_jid', 'like', '%@lid')
            ->update(['is_lid' => true]);
    }

    public function down(): void
    {
        Schema::table('whatsapp_incoming_messages', function (Blueprint $table) {
            $table->dropColumn('is_lid');
        });
    }
};
