<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.2 — fondasi state machine percakapan WhatsApp (generik, TIDAK ADA
 * business logic PSB/OTP spesifik di sini — itu v0.13.3/v0.13.4). State
 * "aktif" sesungguhnya hidup di Redis (App\Services\Whatsapp\
 * WhatsappConversationStateService, TTL 2 jam flat) — tabel ini adalah
 * AUDIT TRAIL TERPISAH, append-only, tidak pernah dibaca balik oleh state
 * machine itu sendiri (state Redis adalah satu-satunya sumber kebenaran
 * untuk "di mana percakapan ini sekarang").
 *
 * Sengaja TIDAK ada tenant_id/reseller_id/customer_id — sama alasan
 * whatsapp_incoming_messages (v0.13.1): resolusi ke customer/technician/
 * tenant adalah business logic v0.13.3+, di luar scope fondasi ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversation_logs', function (Blueprint $table) {
            $table->id();
            // Di-generate sekali saat WhatsappConversationStateService::open()
            // dipanggil — mengelompokkan SEMUA baris (system + pesan) dalam
            // 1 "sesi percakapan" sampai close(). Buka state baru lagi nanti
            // (scope sama atau beda) dapat state_id BARU — TIDAK pernah
            // dipakai ulang.
            $table->string('state_id');
            // Format lokal "0xxx", konsisten whatsapp_incoming_messages.sender_phone.
            $table->string('phone_number');
            $table->string('scope');
            // inbound/outbound = pesan sungguhan (dicatat via method log
            // TERPISAH, dipanggil eksplisit pemanggil v0.13.3+ yang
            // genuinely punya isi pesan — TIDAK otomatis oleh service ini).
            // system = event open()/close() itu sendiri, ditulis OTOMATIS
            // oleh WhatsappConversationStateService, content berisi
            // deskripsi singkat event (mis. "dibuka", "ditutup: selesai",
            // "ditutup: dialihkan ke scope baru").
            $table->string('direction');
            $table->text('content');
            // Tahap SAAT log ini terjadi — nullable karena event system
            // "dibuka" belum tentu punya step (step diisi initialData saat
            // open(), bisa null kalau pemanggil tidak set). Berguna untuk
            // trace urutan tanpa perlu re-parse state Redis yang mungkin
            // sudah TTL habis.
            $table->string('step')->nullable();
            $table->timestamps();

            $table->index('state_id');
            $table->index(['phone_number', 'scope']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversation_logs');
    }
};
