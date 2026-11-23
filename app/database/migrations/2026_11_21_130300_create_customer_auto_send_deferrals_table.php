<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.28.2 (T2) — penundaan notifikasi `customer_registered` (K11). Registrasi
 * bisa terjadi kapan saja; bila gerbang menolak sementara (di luar jendela /
 * sesi putus / pemutus / plafon) pesan DITUNDA, lalu
 * `whatsapp:process-deferred-registered` mengevaluasi ulang gerbang penuh tiap
 * menit. SATU deferral aktif per customer (partial unique WHERE status =
 * 'pending'); selesai ditandai `status`, baris TIDAK dihapus (jejak).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_auto_send_deferrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending|sent|simulated|cancelled
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->index(['tenant_id', 'status']);
        });

        // Satu deferral AKTIF per customer. Partial unique index didukung
        // PostgreSQL (produksi) maupun SQLite (test).
        DB::statement(
            'CREATE UNIQUE INDEX "customer_auto_send_deferrals_one_pending" '.
            'ON "customer_auto_send_deferrals" ("customer_id") WHERE "status" = \'pending\''
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_auto_send_deferrals');
    }
};
