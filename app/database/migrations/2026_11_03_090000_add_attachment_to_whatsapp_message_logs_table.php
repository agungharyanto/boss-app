<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.5.1 — lampiran media opsional per pesan WA. SATU objek lampiran per
 * baris log (satu pesan = satu lampiran = satu job). Objek:
 * {disk, path, mime, filename, caption?} menunjuk file di disk PRIVAT
 * (BUKAN URL). Aditif + nullable — baris teks lama tetap null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            $table->json('attachment')->nullable()->after('rendered_content');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            $table->dropColumn('attachment');
        });
    }
};
