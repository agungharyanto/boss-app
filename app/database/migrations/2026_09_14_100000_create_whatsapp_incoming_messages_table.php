<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.1 — Listener Pesan Masuk WhatsApp. Penyimpanan MENTAH saja, TIDAK
 * ADA state machine/routing/business logic di sub-versi ini (itu v0.13.2+,
 * scope terpisah) — tabel ini murni bukti pesan masuk berhasil ditangkap
 * end-to-end dari WA sampai tersimpan di Laravel.
 *
 * Sengaja TIDAK ada tenant_id/reseller_id/customer_id — webhook ini publik
 * (whatsapp-gateway yang POST, bukan user login), dan resolusi ke
 * customer/technician/tenant adalah business logic yang eksplisit di luar
 * scope v0.13.1 (v0.13.2+).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_incoming_messages', function (Blueprint $table) {
            $table->id();
            $table->string('session_key');
            // Format lokal "0xxx" (App\Support\WhatsappPhone-nya sisi ini
            // adalah jidnorm.ToLocalIndonesian, Go) — SAMA konvensi dengan
            // customers.phone_number/technicians.phone, BUKAN "62xxx" seperti
            // whatsapp_message_logs.phone_number (yang searah beda tujuan,
            // outbound).
            $table->string('sender_phone');
            $table->string('chat_jid');
            // Unik — idempotency guard: whatsmeow bisa mengirim ulang event
            // yang sama (retry/offline-sync), webhook fire-and-forget di sisi
            // Go tidak dijamin sekali kirim.
            $table->string('message_id')->unique();
            $table->text('text');
            $table->string('push_name')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index('session_key');
            $table->index('sender_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_incoming_messages');
    }
};
