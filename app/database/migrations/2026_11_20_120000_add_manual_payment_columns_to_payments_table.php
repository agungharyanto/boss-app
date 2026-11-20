<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.5.5 (T4) — kolom aditif pada `payments` untuk mencatat metode bayar
 * saat invoice ditandai LUNAS. Jalur yang menulis baris payments (selain
 * Xendit yang sudah ada): "Tandai Lunas" manual UI + PATCH API. Perpanjang &
 * PppoeVlan10MigrationService TIDAK menulis baris.
 *
 * `channel_type` DILONGGARKAN jadi nullable: baris manual (Transfer Manual /
 * Agen) tidak punya kode channel gateway — metode nyatanya disimpan di kolom
 * baru `method` (cast App\Enums\PaymentMethod), bukan di `channel_type` (yang
 * tetap untuk kode payment_gateway_channels pada baris Xendit). TANPA backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // App\Enums\PaymentMethod (manual_transfer/payment_gateway/agent).
            // Null = metode tidak tercatat (perilaku lama / jalur tanpa metode).
            $table->string('method')->nullable()->after('channel_type');
            // Admin yang mencatat pembayaran manual (jejak). null = jalur
            // otomatis (Xendit) / tidak diketahui.
            $table->foreignId('recorded_by')->nullable()->after('method')
                ->constrained('users')->nullOnDelete();
            // Catatan referensi opsional (mis. no. mutasi / nama pengirim).
            $table->string('reference_note')->nullable()->after('recorded_by');
            // Rekening tujuan (bila Transfer Manual). null = bukan manual / tak diisi.
            $table->foreignId('company_bank_account_id')->nullable()->after('reference_note')
                ->constrained('company_bank_accounts')->nullOnDelete();
        });

        // Baris manual tidak punya kode channel gateway.
        Schema::table('payments', function (Blueprint $table) {
            $table->string('channel_type')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_bank_account_id');
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropColumn(['method', 'reference_note']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('channel_type')->nullable(false)->change();
        });
    }
};
