<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.5.4 — jejak APPEND-ONLY setiap perubahan rekening (anti-fraud, K10).
 * Nomor rekening disimpan TER-MASK (4 digit terakhir) di before/after.
 * bank_account_id nullable + nullOnDelete supaya log tetap ada setelah rekening
 * dihapus (jejak "siapa menghapus").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_bank_account_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('company_bank_accounts')->nullOnDelete();
            $table->string('action'); // created|updated|deleted|made_default|activated|deactivated
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'bank_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_bank_account_logs');
    }
};
