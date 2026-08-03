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
        Schema::create('payment_gateway_channels', function (Blueprint $table) {
            $table->id();
            // Stable identifier matched against payments.channel_type — no
            // hard FK constraint (validated in PaymentService instead, see
            // its own migration's docblock for why), so a channel can be
            // renamed/retired in the catalog without touching historical
            // payments rows.
            $table->string('code')->unique();
            $table->string('label');
            $table->string('category');
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_channels');
    }
};
