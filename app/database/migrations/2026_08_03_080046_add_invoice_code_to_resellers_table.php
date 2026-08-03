<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            // Short code for invoice numbering (v0.3.4, per-reseller invoice
            // sequence — INV/{invoice_code}/{year}/{month}/{seq}). Nullable:
            // ResellerService auto-derives one from the reseller's slug if
            // not set explicitly (see ResellerService::deriveInvoiceCode()).
            $table->string('invoice_code')->nullable()->after('slug');
            $table->unique(['tenant_id', 'invoice_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'invoice_code']);
            $table->dropColumn('invoice_code');
        });
    }
};
