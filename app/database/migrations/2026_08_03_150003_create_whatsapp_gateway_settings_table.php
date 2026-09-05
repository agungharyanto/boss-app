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
        // Platform-level singleton (id=1), same posture as
        // payment_gateway_settings — one gateway-wide rate limit policy
        // for the whole ISP deployment, not per-tenant/per-reseller (a
        // reseller cannot set their own rate limit this sprint, see
        // docs/ROADMAP.md deferred items).
        Schema::create('whatsapp_gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('rate_limit_delay_min_seconds')->default(5);
            $table->unsignedSmallInteger('rate_limit_delay_max_seconds')->default(10);
            $table->unsignedSmallInteger('rate_limit_batch_size')->default(20);
            $table->unsignedSmallInteger('rate_limit_batch_pause_min_minutes')->default(5);
            $table->unsignedSmallInteger('rate_limit_batch_pause_max_minutes')->default(10);
            // No DB-level default (jsonb default literals behave
            // inconsistently across the sqlite-test / postgres-prod driver
            // split this codebase runs on) — WhatsappGatewaySettings::get()
            // supplies ['08:00', '20:00'] whenever the singleton row hasn't
            // been created yet.
            $table->json('daily_schedule_times')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_gateway_settings');
    }
};
