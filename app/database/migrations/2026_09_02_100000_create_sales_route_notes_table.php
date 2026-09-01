<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 Langkah 11 — a sales rep's saved "route to the ODP" reference
 * for a prospect/customer. Pure reference: never read by any
 * billing/invoicing code. customer_id is nullable — a prospect isn't a
 * Customer row yet; prospect_name/prospect_address carry the free-text
 * identity in that case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_route_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('prospect_name')->nullable();
            $table->string('prospect_address')->nullable();
            $table->decimal('from_latitude', 10, 7);
            $table->decimal('from_longitude', 10, 7);
            $table->foreignId('target_odp_id')->constrained('odps')->cascadeOnDelete();
            $table->string('route_label')->nullable();
            $table->json('route_geometry');
            $table->unsignedInteger('distance_meters');
            $table->boolean('is_straight_line_estimate')->default(false);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'target_odp_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_route_notes');
    }
};
