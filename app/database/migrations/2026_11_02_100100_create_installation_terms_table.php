<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.19.1 — Syarat & Ketentuan Pemasangan, INSERT-ONLY (append-only
 * versioning). Tiap simpan di editor = baris versi BARU; versi aktif =
 * `version` tertinggi per tenant. Tanda tangan lama (nanti disambung di
 * lanjutan v0.13.4.3.1) tetap terikat pada SNAPSHOT teksnya sendiri di
 * `work_order_signatures`, jadi baris lama tidak pernah diubah/dihapus.
 *
 * Sengaja tanpa `updated_at` — baris tidak pernah di-update setelah dibuat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            // Satu versi per angka per tenant — "pakai ulang versi ini"
            // selalu membuat angka versi BARU, bukan menimpa angka lama.
            $table->unique(['tenant_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_terms');
    }
};
