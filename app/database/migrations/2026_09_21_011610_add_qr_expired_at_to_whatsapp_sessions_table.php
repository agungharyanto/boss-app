<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redesign trigger overlay reload QR — dari tebakan waktu (counter 3s×5x
 * sisi Livewire) jadi sinyal GENUINE dari whatsmeow (channel QR benar-benar
 * habis, whatsmeow sendiri sudah memanggil client.Disconnect(), lihat
 * whatsapp-gateway/internal/session/manager.go drainQRChannel()'s "timeout"
 * case non-pertama). `status` TIDAK berubah jadi "disconnected" — string
 * itu sudah dipakai skenario transient-reconnect yang semantiknya beda
 * total (creds masih valid, auto-reconnect backoff 5s-60s) — kolom
 * TERPISAH ini menghindari ambiguitas itu tanpa perlu status enum baru.
 * `null` = QR (kalau ada) masih genuinely valid untuk discan; terisi =
 * whatsmeow sudah menyerah, perlu klik "Refresh QR Code" untuk QR baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->timestamp('qr_expired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->dropColumn('qr_expired_at');
        });
    }
};
