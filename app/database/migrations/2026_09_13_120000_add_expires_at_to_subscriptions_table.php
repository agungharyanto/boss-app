<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v0.12.2 (migrasi PPPoE VLAN10 -> radcheck) — Track A butuh kolom untuk
     * menyimpan tanggal jatuh tempo/expired langganan (dari `Expired`
     * mixradius) pada baris `subscriptions` masing-masing pelanggan
     * ter-migrasi. `subscriptions` sebelum ini tidak punya konsep "kapan
     * periode berjalan berakhir" sama sekali di luar `invoices.period_end`.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->date('expires_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
