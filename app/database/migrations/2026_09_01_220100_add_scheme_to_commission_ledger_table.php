<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.9.4 — `commission_ledger.scheme`: skema komisi yang dipilih untuk
 * atribusi ini (`App\Enums\CommissionScheme`: `recurring` / `limited_count`).
 * Nullable — baris lama (dan baris baru dari admin yang skip pilihan skema)
 * tetap `scheme = NULL, amount = NULL` seperti perilaku v0.9.3 dan
 * sebelumnya. Kolom `amount` (sudah ada sejak v0.3.0) baru mulai benar-benar
 * diisi di v0.9.4 saat skema dipilih.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->string('scheme')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropColumn('scheme');
        });
    }
};
