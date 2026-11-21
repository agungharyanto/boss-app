<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.28.2 (T2) — pemutus kirim otomatis per (tenant, sesi). Terbuka bila N
 * kegagalan otomatis beruntun tercapai (K12). Sesi tidak Connected dicek LIVE
 * di gerbang (bukan dari flag ini). Auto-lanjut saat sesi transisi ke
 * Connected (WhatsappSessionService::applyStatus) atau perintah artisan
 * `whatsapp:auto-send-resume`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_auto_send_breakers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('session_key'); // 'direct' | reseller_id
            $table->boolean('is_open')->default(false);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->string('open_reason')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->foreignId('resumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'session_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_auto_send_breakers');
    }
};
