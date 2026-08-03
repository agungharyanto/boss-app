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
        // Platform-level singleton — one account is used across the whole
        // ISP, not per-tenant/per-reseller (same reasoning as
        // payment_webhook_logs: deliberately no tenant_id). Only
        // App\Services\Payment\PaymentGatewaySettingsService is allowed to
        // read/write this table in application code — it always targets a
        // single fixed row (id=1) via firstOrCreate/lockForUpdate, which is
        // what actually enforces "only ever one row", not a DB constraint.
        Schema::create('payment_gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->text('xendit_secret_key')->nullable();
            $table->text('xendit_webhook_token')->nullable();
            $table->boolean('is_configured')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_settings');
    }
};
