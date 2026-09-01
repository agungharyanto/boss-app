<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 — Core Network Infrastructure Management, Langkah 1 (schema-only,
 * lihat docs/ROADMAP.md untuk detail lengkap). Titik topologi fiber selain
 * ODP (yang tetap di tabel `odps`, v0.5.0, existing) — OTB/Closure/ODC.
 *
 * `parent_type`/`parent_id` — morph self-referencing (mis. Closure di
 * bawah OTB) TANPA FK constraint (pola sama App\Models\ResellerTaxLedger::
 * reference(), v0.3.3 — satu-satunya morph relation existing sebelum ini),
 * karena parent bisa berupa fiber_nodes lain ATAU odps (dua tabel
 * berbeda), tidak bisa diwakili satu FK constraint tunggal.
 *
 * `latitude`/`longitude` NULLABLE — beda dari `odps` (NOT NULL sejak
 * v0.5.0) karena sebuah baris fiber_nodes bisa saja dibuat sebelum
 * koordinat GPS-nya sempat disurvei di lapangan.
 *
 * `loss_in_db`/`loss_out_db` NULLABLE, TANPA constraint apa pun di level
 * DB/Model — dikoreksi eksplisit dari draft desain awal (lihat catatan
 * "Koreksi Langkah 0 poin 2" di docs/ROADMAP.md). "Wajib diisi" HANYA akan
 * berlaku sebagai validasi FormRequest di Langkah 2 (form input/edit data
 * splice), bukan constraint di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiber_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Null = milik ISP A langsung, pola sama odps.reseller_id.
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
            // App\Enums\FiberNodeType (otb/closure/odc) — dibangun Langkah 2.
            $table->string('node_type');
            $table->string('local_label')->nullable();
            $table->string('parent_type')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('loss_in_db', 6, 2)->nullable();
            $table->decimal('loss_out_db', 6, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index(['parent_type', 'parent_id']);
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiber_nodes');
    }
};
