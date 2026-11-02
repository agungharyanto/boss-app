<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.4.3.1 — tanda tangan pelanggan saat penutupan WO via form web
 * teknisi. SATU tanda tangan per WO (`work_order_id` unique). `file_path`
 * = PNG di disk PRIVAT ('local', storage/app/private/), tak pernah
 * disajikan publik. `terms_snapshot` menyimpan teks persyaratan LENGKAP
 * yang disetujui (bukan cuma versi) supaya bukti persetujuan tetap utuh
 * walau teks default di pengaturan tenant berubah belakangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->unique()->constrained('work_orders')->cascadeOnDelete();
            $table->string('file_path');
            $table->timestamp('signed_at');
            $table->string('terms_version');
            $table->text('terms_snapshot');
            $table->string('signed_ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_signatures');
    }
};
