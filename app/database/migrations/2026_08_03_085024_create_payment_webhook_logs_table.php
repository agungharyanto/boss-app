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
        Schema::create('payment_webhook_logs', function (Blueprint $table) {
            $table->id();
            // Idempotency backstop (mirrors the unique constraint pattern in
            // InvoiceService::generateForPeriod, v0.3.4) — PaymentService's
            // own in-transaction existence check is the primary guard, this
            // constraint is what makes a race under concurrent webhook
            // delivery actually safe. No FK/tenant_id here on purpose: this
            // table logs EVERY inbound webhook attempt (including rejected
            // ones we can't yet attribute to a tenant/invoice), a
            // platform-wide security audit trail, not tenant-scoped data.
            $table->string('xendit_event_id')->unique();
            $table->jsonb('payload');
            $table->boolean('signature_valid');
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_result');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_logs');
    }
};
