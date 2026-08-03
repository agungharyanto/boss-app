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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            // Denormalized from subscriptions.reseller_id — cheap reporting
            // queries without joining through subscriptions every time,
            // same rationale as customers.reseller_id in v0.3.2.
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
            $table->string('invoice_number');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->string('status')->default('draft');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_total', 14, 2);
            $table->decimal('grand_total', 14, 2);
            $table->timestamp('generated_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique('invoice_number');
            // One invoice per subscription per billing period — the DB-level
            // safety net behind InvoiceService::generateForSubscription()'s
            // own existence check (BOSS-004 test: "no duplicate invoice for
            // the same subscription+period").
            $table->unique(['subscription_id', 'period_start', 'period_end']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'reseller_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
