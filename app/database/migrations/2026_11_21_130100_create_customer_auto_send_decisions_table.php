<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.28.2 (T2) — jejak keputusan gerbang (SIMULASI & DILEWATI). Bukan
 * append-only seperti log opt-out — ini data operasional yang di-prune > 30
 * hari. Hasil `Queued` & `SkippedAutoNotifyOff` TIDAK dicatat (hindari noise):
 * Queued sudah tercatat di whatsapp_message_logs; SkippedAutoNotifyOff = state
 * normal tenant yang notifikasinya OFF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_auto_send_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('path'); // nilai WhatsappEventType (jalur otomatis)
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('result'); // nilai CustomerAutoSendResult
            $table->boolean('simulated')->default(false);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'path', 'result', 'created_at']);
            $table->index(['customer_id']);
            $table->index(['invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_auto_send_decisions');
    }
};
