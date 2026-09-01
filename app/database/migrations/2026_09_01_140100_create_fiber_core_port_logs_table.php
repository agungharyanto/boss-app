<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 Langkah 7. Audit ringan: siapa & kapan mengubah patching port
 * (port_number / tautan OLT) sebuah FiberCore di sebuah OTB — supaya
 * salah-assign bisa ditelusuri, bukan silent overwrite. Pola `performed_by`
 * nullable sama seperti `cpe_action_logs` (v0.7.5 amendment): nullable
 * karena secara prinsip bisa ada perubahan non-manusia nanti, walau
 * sekarang selalu dari aksi user.
 *
 * Nilai OLT disimpan sebagai LABEL denormalized ("ZTE C300 - PON 1"),
 * bukan FK — biar riwayat tetap terbaca meski OLT-nya kemudian dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiber_core_port_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiber_core_id')->constrained('fiber_cores')->cascadeOnDelete();
            $table->foreignId('fiber_node_id')->constrained('fiber_nodes')->cascadeOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('old_port_number')->nullable();
            $table->unsignedInteger('new_port_number')->nullable();
            $table->string('old_olt_label')->nullable();
            $table->string('new_olt_label')->nullable();
            $table->timestamps();

            $table->index(['fiber_node_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiber_core_port_logs');
    }
};
