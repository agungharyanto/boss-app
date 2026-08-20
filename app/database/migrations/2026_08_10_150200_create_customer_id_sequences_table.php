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
        Schema::create('customer_id_sequences', function (Blueprint $table) {
            $table->id();
            // Scoped by tenant_id (not just `code` alone) even though
            // tenants.code is already globally unique on its own — a
            // reseller's own code is only unique WITHIN its tenant (see
            // resellers.code), so two unrelated resellers in different
            // tenants could in principle derive the same short code.
            // Scoping the counter by tenant_id means that can never make
            // two different businesses share one CID numbering sequence.
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_id_sequences');
    }
};
