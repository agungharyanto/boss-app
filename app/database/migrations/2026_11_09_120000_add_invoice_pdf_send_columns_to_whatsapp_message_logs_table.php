<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.5.3 — kirim PDF invoice manual ke pelanggan. Dua kolom aditif:
 *  - sent_by_user_id: pelaku (admin) kirim manual — null untuk kiriman
 *    otomatis/sistem (4 event pelanggan lama + command). FK nullOnDelete.
 *  - is_test: baris log hasil "Mode Uji" (penerima diganti ke nomor uji,
 *    pelanggan nyata TIDAK menerima apa pun). Penanda "sudah terkirim ke
 *    pelanggan" di UI HANYA menghitung baris non-uji.
 *
 * Aditif murni — tidak menyentuh baris lama (default is_test=false,
 * sent_by_user_id null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            $table->foreignId('sent_by_user_id')->nullable()->after('template_id')
                ->constrained('users')->nullOnDelete();
            $table->boolean('is_test')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by_user_id');
            $table->dropColumn('is_test');
        });
    }
};
