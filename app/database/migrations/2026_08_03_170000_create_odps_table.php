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
        Schema::create('odps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Null = ODP milik ISP A langsung, sama pola dengan whatsapp_sessions/
            // customers.reseller_id (v0.3.2/v0.4.0) — bukan direct-row terpisah.
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('total_ports');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index('tenant_id');
            $table->index(['latitude', 'longitude']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('odps');
    }
};
