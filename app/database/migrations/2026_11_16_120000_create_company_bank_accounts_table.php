<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.5.4 — rekening tujuan transfer manual, bisa lebih dari satu per tenant.
 * Menggantikan kolom tunggal company_profiles.bank_* (dibiarkan, deprecated).
 *
 * Partial unique index "satu default aktif per tenant" (driver-conditional,
 * pola sama whatsapp_notification_groups v0.13.4.4). SENGAJA TIDAK pakai
 * BelongsToTenant di model — PaymentInstructionsService membacanya dari jalur
 * render PDF/sweep yang bisa tanpa Auth; semua query lewat tenant_id eksplisit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('account_holder');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        // Tepat satu rekening default AKTIF per tenant.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX company_bank_accounts_one_default ON company_bank_accounts (tenant_id) WHERE is_default AND is_active');
        } else {
            DB::statement('CREATE UNIQUE INDEX company_bank_accounts_one_default ON company_bank_accounts (tenant_id) WHERE is_default = 1 AND is_active = 1');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_bank_accounts');
    }
};
