<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v0.22.7 — begitu staff yang JUGA Referrer dihapus (StaffService::
     * delete()), setiap customer yang mengarah ke Referrer itu
     * (referred_by_referrer_id) genuinely dikosongkan (bukan dibiarkan
     * nyantol ke Referrer tanpa login) — `referral_locked=true` +
     * `locked_former_referrer_name` mencatat siapa referrer-nya dulu,
     * supaya jejaknya tidak hilang sama sekali dari tampilan meski
     * relasinya sendiri sudah diputus. TIDAK ada migration data
     * retroaktif — dikonfirmasi Agung, kasus lama (Kamisem) sudah
     * dibereskan manual sebelum kolom ini ada.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('referral_locked')->default(false);
            $table->string('locked_former_referrer_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['referral_locked', 'locked_former_referrer_name']);
        });
    }
};
