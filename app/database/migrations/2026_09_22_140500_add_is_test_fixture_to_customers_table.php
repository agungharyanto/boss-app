<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.23.5 — flag customer dummy/QA murni untuk mengecualikan dari
 * laporan/billing pelanggan asli (mis. akun uji aktivasi ONU ZTE C300
 * end-to-end). TIDAK ADA mekanisme flag test sebelumnya di skema
 * `customers` (dikonfirmasi lewat investigasi Langkah 0 v0.23.5 — grep
 * migration/model/CLAUDE.md kosong; fixture permanen `085166445368` murni
 * baris `radius_db.radcheck`/`radreply`, tidak punya baris `customers`
 * sama sekali, jadi tidak ada pola existing untuk direuse).
 *
 * Titik laporan/billing yang perlu di-exclude (dashboard ringkasan
 * pelanggan, export CSV) BELUM diaudit lengkap di sub-versi ini — dicatat
 * sebagai item backlog terpisah, bukan diselesaikan sekarang (keputusan
 * eksplisit Agung).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_test_fixture')->default(false)->after('status');
            $table->index('is_test_fixture');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['is_test_fixture']);
            $table->dropColumn('is_test_fixture');
        });
    }
};
