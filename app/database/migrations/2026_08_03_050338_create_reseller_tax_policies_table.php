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
        Schema::create('reseller_tax_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->cascadeOnDelete();
            $table->foreignId('tax_component_id')->constrained('tax_components')->cascadeOnDelete();
            $table->string('burden');
            // split_ratio validity (required + 0-100 range when burden=split)
            // is enforced in App\Services\Tax\ResellerTaxPolicyService, not a
            // DB constraint — burden-dependent validation doesn't map cleanly
            // to a CHECK constraint without duplicating the enum in SQL.
            $table->decimal('split_ratio', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // reseller_id is nullable (null = direct-retail policy), so a plain
        // multi-column unique constraint can't express "one row per
        // reseller+component+effective_from, OR one row per
        // component+effective_from when reseller_id IS NULL" — Postgres
        // treats every NULL as distinct in a regular unique index, which
        // would let unlimited direct-retail duplicates through. Two partial
        // unique indexes instead, split on reseller_id IS [NOT] NULL.
        DB::statement('
            CREATE UNIQUE INDEX reseller_tax_policies_reseller_unique
            ON reseller_tax_policies (tenant_id, reseller_id, tax_component_id, effective_from)
            WHERE reseller_id IS NOT NULL
        ');

        DB::statement('
            CREATE UNIQUE INDEX reseller_tax_policies_direct_retail_unique
            ON reseller_tax_policies (tenant_id, tax_component_id, effective_from)
            WHERE reseller_id IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_tax_policies');
    }
};
