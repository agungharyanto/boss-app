<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 — Core Network Infrastructure Management, Langkah 1. Sengaja
 * HANYA menambah 4 kolom baru — tidak menyentuh kolom `odps` yang sudah
 * ada (latitude/longitude tetap NOT NULL seperti semula) dan sama sekali
 * tidak menyentuh `odp_ports`, per instruksi eksplisit.
 *
 * `parent_type`/`parent_id` — morph ke `fiber_nodes` (mis. ODP di bawah
 * Closure/ODC tertentu), pola sama dengan fiber_nodes.parent_type/
 * parent_id sendiri (lihat migration create_fiber_nodes_table).
 *
 * `loss_in_db`/`loss_out_db` — NULLABLE, TANPA constraint DB/Model apa
 * pun. Banyak baris `odps` lama (v0.5.0) belum pernah disurvei redamannya
 * di lapangan; alur existing (registrasi pelanggan, OdpLocatorService,
 * assignment port WorkOrder) tidak boleh terganggu sama sekali oleh
 * kolom baru ini. "Wajib diisi" hanya akan berlaku sebagai validasi
 * FormRequest di form data splice (Langkah 2), untuk SEMUA baris odps
 * (satu baris odps = satu ODP, tidak butuh gate node_type).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odps', function (Blueprint $table) {
            $table->string('parent_type')->nullable()->after('reseller_id');
            $table->unsignedBigInteger('parent_id')->nullable()->after('parent_type');
            $table->decimal('loss_in_db', 6, 2)->nullable()->after('total_ports');
            $table->decimal('loss_out_db', 6, 2)->nullable()->after('loss_in_db');

            $table->index(['parent_type', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('odps', function (Blueprint $table) {
            $table->dropIndex(['parent_type', 'parent_id']);
            $table->dropColumn(['parent_type', 'parent_id', 'loss_in_db', 'loss_out_db']);
        });
    }
};
