<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.5.4 — saklar metode pembayaran di invoice, per tenant (company_profiles
 * = tabel setelan perusahaan paling wajar, satu baris per tenant, sudah memuat
 * bank/CS). Default: transfer manual ON, gateway & agen OFF (K5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->boolean('pay_manual_transfer_enabled')->default(true);
            $table->boolean('pay_gateway_enabled')->default(false);
            $table->boolean('pay_agent_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn(['pay_manual_transfer_enabled', 'pay_gateway_enabled', 'pay_agent_enabled']);
        });
    }
};
