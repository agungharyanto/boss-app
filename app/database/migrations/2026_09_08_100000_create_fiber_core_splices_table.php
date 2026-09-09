<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.1 Bagian B — core-to-core splice continuity at a passive node
 * (Closure/ODC/ODP). One row = "core X of cable A is spliced straight
 * through to core Y of cable B, at this node". Distinct from a Splitter
 * (1->N power split) — this is a plain 1:1 through-splice.
 *
 * `splice_node_type`/`splice_node_id` is a polymorphic pair (same shape
 * as `fiber_cables.from_type`/`from_id`) so a splice can live at a
 * `FiberNode` (Closure/ODC) OR an `Odp` — item #6 of the sprint scope
 * explicitly needs ODP too, and ODP is a separate table from
 * `fiber_nodes`. No hard FK on it for that reason; a live guard in
 * FiberCoreSpliceService + an Odp/FiberNode `deleting` cleanup carry the
 * integrity instead.
 *
 * `from_fiber_core_id`/`to_fiber_core_id` each carry a UNIQUE index — a
 * backstop for the common "this exact column already holds that core"
 * case. The real "a core can be in at most ONE splice, on EITHER side"
 * rule spans both columns and is enforced in the service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiber_core_splices', function (Blueprint $table) {
            $table->id();
            $table->string('splice_node_type');
            $table->unsignedBigInteger('splice_node_id');
            $table->foreignId('from_fiber_core_id')->constrained('fiber_cores')->cascadeOnDelete();
            $table->foreignId('to_fiber_core_id')->constrained('fiber_cores')->cascadeOnDelete();
            $table->decimal('loss_db', 5, 2)->nullable();
            $table->string('note')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('from_fiber_core_id');
            $table->unique('to_fiber_core_id');
            $table->index(['splice_node_type', 'splice_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiber_core_splices');
    }
};
