<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.28.4 (SEBAR-BATCH) — kursor jeda-batch per (tenant, sesi). Menyimpan
 * posisi batch yang BERTAHAN lintas tick everyMinute (D2): `next_slot_at` =
 * waktu dinding kapan pesan otomatis berikutnya boleh diantre, `batch_count` =
 * posisi dalam batch berjalan. Terpisah dari customer_auto_send_breakers
 * (state kegagalan ≠ state pacing; reset pemutus TIDAK menyentuh kursor —
 * keputusan A4). Tanpa BelongsToTenant (dipakai dari gerbang via job/command
 * tanpa Auth; tenant_id eksplisit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_auto_send_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('session_key'); // 'direct' | reseller_id
            $table->timestamp('next_slot_at')->nullable();
            $table->unsignedInteger('batch_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'session_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_auto_send_cursors');
    }
};
