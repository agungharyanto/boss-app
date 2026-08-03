<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoice_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Null = direct-retail counter, same convention as
            // reseller_tax_policies/komdigi_remittance_summary.
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();
        });

        // Same partial-unique-index pattern as reseller_tax_policies —
        // reseller_id nullable means a plain unique constraint can't express
        // "one counter row per reseller+year+month, OR one row per
        // year+month when reseller_id IS NULL".
        DB::statement('
            CREATE UNIQUE INDEX invoice_number_sequences_reseller_unique
            ON invoice_number_sequences (tenant_id, reseller_id, year, month)
            WHERE reseller_id IS NOT NULL
        ');

        DB::statement('
            CREATE UNIQUE INDEX invoice_number_sequences_direct_retail_unique
            ON invoice_number_sequences (tenant_id, year, month)
            WHERE reseller_id IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_number_sequences');
    }
};
