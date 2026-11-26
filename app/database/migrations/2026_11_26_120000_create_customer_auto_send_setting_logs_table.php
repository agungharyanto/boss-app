<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.28.5 (UI tab "Kirim Otomatis") — jejak APPEND-ONLY setiap perubahan
 * pengaturan kirim otomatis pelanggan (satu baris per FIELD yang berubah:
 * user, waktu, field, before/after). Tidak ada tabel log yang sudah ada untuk
 * pengaturan ini (CompanyBankAccountLog = rekening; CustomerNotificationOptOutLog
 * = opt-out per-pelanggan) — tabel kecil sendiri. Model menegakkan append-only
 * (menolak update/delete) seperti CustomerNotificationOptOutLog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_auto_send_setting_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_auto_send_setting_logs');
    }
};
