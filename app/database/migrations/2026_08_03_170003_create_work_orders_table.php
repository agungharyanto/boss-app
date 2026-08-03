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
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Denormalized from subscription.reseller_id at creation time —
            // cheap reseller-scoped queries without joining through
            // subscriptions every time, same rationale as invoices.reseller_id
            // (v0.3.4). Nullable for the same reason subscriptions.reseller_id
            // is nullable — a direct-retail subscription has none.
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('technicians')->nullOnDelete();
            $table->foreignId('odp_id')->nullable()->constrained('odps')->nullOnDelete();
            $table->foreignId('odp_port_id')->nullable()->constrained('odp_ports')->nullOnDelete();
            $table->string('status')->default('pending_odp_check');
            // Manual placeholder until the real stock/inventory module exists
            // (out of scope this sprint, see docs/ROADMAP.md) — verify() sets
            // this from an admin's manual confirmation, not real stock data.
            $table->boolean('equipment_ready')->default(false);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'reseller_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
