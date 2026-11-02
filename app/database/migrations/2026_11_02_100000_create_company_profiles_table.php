<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.19.1 — Profil Perusahaan per TENANT (singleton per tenant, `tenant_id`
 * unique). Menggantikan ketergantungan identitas perusahaan pada
 * config/invoice.php (env INVOICE_COMPANY_*), yang tetap dipertahankan
 * sebagai fallback TERAKHIR di CompanyProfileService — tidak dihapus.
 *
 * Bagian BRANDING tenant yang sejak v0.2.0 "deferred to their own future
 * sprint" (lihat CLAUDE.md multi-tenancy) — sprint itu adalah ini, TAPI
 * HANYA bagian tenant. Reseller cuma boleh override nomor CS (lihat migrasi
 * add_cs_override_to_resellers_table), bukan brand/logo/legal/NPWP/rekening.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();

            // Identitas
            $table->string('legal_name')->nullable();
            $table->string('brand_name')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('npwp')->nullable();

            // Kontak
            $table->string('cs_whatsapp')->nullable();
            $table->string('cs_phone')->nullable();
            $table->string('email')->nullable();
            $table->text('operating_hours')->nullable();

            // Pembayaran
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_account_holder')->nullable();

            // Branding — file logo privat, di-stream lewat controller ber-auth
            // (bukan storage publik), lihat CompanyLogoController.
            $table->string('logo_path')->nullable();

            // Audit — siapa yang terakhir mengubah profil.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_profiles');
    }
};
