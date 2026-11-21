<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.28.1 (T1) — jejak APPEND-ONLY setiap opt-out/opt-in notifikasi otomatis
 * pelanggan. Pola mengikuti `company_bank_account_logs` (hanya `created_at`,
 * before/after json, user_id nullable), TAPI lebih ketat: model
 * `CustomerNotificationOptOutLog` MENOLAK update & delete (append-only
 * ditegakkan di level model, bukan hanya service).
 *
 * `reason` wajib (NOT NULL). `customer_id` cascadeOnDelete (jejak ikut terhapus
 * saat pelanggan dihapus — cascade DB, bukan lewat Eloquent, jadi tak terbentur
 * guard model). `user_id` nullOnDelete (jejak tetap ada walau user dihapus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notification_opt_out_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('action'); // opted_out | opted_in
            $table->text('reason');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notification_opt_out_logs');
    }
};
