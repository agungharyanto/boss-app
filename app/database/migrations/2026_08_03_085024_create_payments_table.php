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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            // Nullable — a failed create-payment call to Xendit (network
            // error, 4xx/5xx) may never receive an id back at all; the row
            // is still worth keeping (status=failed) as an audit trail of
            // the attempt, per BOSS-005 audit-log posture.
            $table->string('xendit_reference_id')->nullable();
            $table->string('channel_type');
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('pending');
            $table->timestamp('paid_at')->nullable();
            // Full create-payment API response snapshot — not re-derived
            // from Xendit later, same "snapshot at the time" principle as
            // reseller_tax_ledger's rate_applied/burden_applied (v0.3.3).
            $table->jsonb('raw_response')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'invoice_id']);
            $table->index('xendit_reference_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
