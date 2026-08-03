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
        Schema::create('komdigi_remittance_summary', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // period_start/period_end are always calendar month boundaries
            // (confirmed scope) — not enforced at the DB level, just a
            // convention App\Services\Tax\RemittanceSummaryService::generateForPeriod()
            // follows.
            $table->date('period_start');
            $table->date('period_end');
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
            $table->foreignId('tax_component_id')->constrained('tax_components')->cascadeOnDelete();
            $table->decimal('total_base_amount', 14, 2);
            $table->decimal('total_tax_amount', 14, 2);
            $table->decimal('total_customer_borne', 14, 2);
            $table->decimal('total_reseller_borne', 14, 2);
            $table->integer('transaction_count');
            $table->string('status')->default('draft');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('remitted_at')->nullable();
            $table->timestamps();
        });

        // Same reasoning as reseller_tax_policies: reseller_id nullable
        // means a plain unique constraint can't express "one summary row per
        // reseller+component+period, OR one row per component+period when
        // reseller_id IS NULL (direct-retail aggregate)".
        DB::statement('
            CREATE UNIQUE INDEX komdigi_remittance_summary_reseller_unique
            ON komdigi_remittance_summary (tenant_id, period_start, period_end, reseller_id, tax_component_id)
            WHERE reseller_id IS NOT NULL
        ');

        DB::statement('
            CREATE UNIQUE INDEX komdigi_remittance_summary_direct_retail_unique
            ON komdigi_remittance_summary (tenant_id, period_start, period_end, tax_component_id)
            WHERE reseller_id IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('komdigi_remittance_summary');
    }
};
