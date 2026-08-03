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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Denormalized from customers.reseller_id at creation time — a
            // hard requirement carried over from v0.3.2/v0.3.3 (see
            // docs/ROADMAP.md dependency notes): must exist from this very
            // first migration, not retrofitted later.
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
            // Nullable — only set for reseller-attributed subscriptions
            // (source of price + tax burden allocation for that reseller).
            // A direct-retail subscription (reseller_id null) has no
            // reseller_package_pricing to point at; its price is set
            // directly on this row instead (monthly_amount below) since no
            // ISP-wide package catalog table exists anywhere in this
            // codebase (see v0.3.2 CHANGELOG.md).
            $table->foreignId('reseller_package_pricing_id')->nullable()->constrained('reseller_package_pricing')->nullOnDelete();
            $table->string('name');
            $table->decimal('monthly_amount', 12, 2);
            $table->string('status')->default('active');
            $table->unsignedTinyInteger('billing_cycle_day');
            $table->date('started_at');
            $table->date('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'reseller_id']);
            $table->index('billing_cycle_day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
