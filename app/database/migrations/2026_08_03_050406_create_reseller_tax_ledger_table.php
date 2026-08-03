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
        Schema::create('reseller_tax_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
            $table->foreignId('tax_component_id')->constrained('tax_components')->cascadeOnDelete();

            // Polymorphic reference, deliberately WITHOUT a foreign key
            // constraint — reference_type will point at App\Models\Invoice
            // starting v0.3.4 (a table that doesn't exist yet in v0.3.3),
            // and this column is designed to stay generic/stable across
            // whatever future reference types get written here.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->decimal('base_amount', 14, 2);
            // Snapshot of tax_components.rate at calculation time — must NOT
            // be re-derived from the live tax_components row later, since
            // TaxComponentService::updateRate() effective-dates a new row
            // rather than mutating history. This is the audit trail.
            $table->decimal('rate_applied', 10, 4);
            $table->decimal('tax_amount', 14, 2);
            // Snapshot of the resolved policy's burden at calculation time,
            // same reasoning as rate_applied — a policy change later must
            // not retroactively alter historical ledger rows.
            $table->string('burden_applied');
            $table->decimal('customer_borne_amount', 14, 2)->nullable();
            $table->decimal('reseller_borne_amount', 14, 2)->nullable();
            $table->date('transaction_date');
            $table->string('status')->default('pending');
            // Distinguishes v0.3.3 seeder/testing rows from rows a real
            // invoice trigger will write starting v0.3.4.
            $table->string('source')->default('system');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'reseller_id', 'transaction_date']);
            $table->index(['tenant_id', 'tax_component_id', 'transaction_date']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_tax_ledger');
    }
};
