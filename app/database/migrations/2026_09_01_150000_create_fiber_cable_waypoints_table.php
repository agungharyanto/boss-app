<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 Langkah 8 — ordered intermediate points for one fiber_cable's
 * drawn route on the "Peta Topologi" page. A cable with zero waypoints
 * renders as a straight line between its from/to endpoints; each waypoint
 * bends the polyline at that coordinate, in `sequence` order.
 *
 * No tenant_id — a waypoint is scoped implicitly through its cable (same
 * pattern as fiber_cores / fiber_accessories). cascadeOnDelete: a route
 * has no meaning once its cable is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiber_cable_waypoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiber_cable_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamps();

            $table->index(['fiber_cable_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiber_cable_waypoints');
    }
};
