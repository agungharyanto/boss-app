<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.4.3.1 — teks persyaratan penutupan WO (yang pelanggan setujui saat
 * tanda tangan) disimpan per-tenant, numpang di `work_order_dispatch_settings`
 * yang sudah per-tenant (keputusan Agung: jangan bangun tabel/UI pengaturan
 * baru yang besar). `close_terms_version` dinaikkan manual tiap teks diubah,
 * disalin ke `work_order_signatures.terms_version/terms_snapshot` saat tanda
 * tangan. Default teks DRAFT di-seed terpisah; placeholder [..] diisi Agung
 * sebelum dipakai pelanggan nyata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->text('close_terms_text')->nullable()->after('wa_group_jid');
            $table->string('close_terms_version')->default('v1-draft')->after('close_terms_text');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->dropColumn(['close_terms_text', 'close_terms_version']);
        });
    }
};
