<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 6. Jumlah port
 * fisik sebuah OTB — untuk "Simulasi Port" di FiberNodeDetail.
 *
 * NULLABLE tanpa constraint di level DB (pola sama loss_in_db/loss_out_db
 * di create_fiber_nodes_table) — "wajib diisi HANYA saat node_type=otb"
 * ditegakkan di FormRequest/Livewire, bukan di sini; OTB lain bisa saja
 * dibuat sebelum jumlah port-nya diketahui. Tidak relevan untuk
 * Closure/ODC/ODP (mereka bukan panel terminasi port).
 *
 * Migration BARU, bukan edit ke create_fiber_nodes_table (v0.16.0
 * Langkah 1) — migration itu sudah dijalankan di server dev/prod-in-place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiber_nodes', function (Blueprint $table) {
            $table->unsignedInteger('port_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fiber_nodes', function (Blueprint $table) {
            $table->dropColumn('port_count');
        });
    }
};
