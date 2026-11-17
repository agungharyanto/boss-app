<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.6 — TERIMA media masuk. Kolom aditif pada `whatsapp_incoming_messages`
 * (semua nullable; pesan teks biasa tetap NULL semua di kolom ini). File
 * disalin ke disk PRIVAT boss-app (`storage/app/private/whatsapp-incoming/...`);
 * `media_file_path` menunjuk ke sana, BUKAN ke volume gateway. `customer_id`
 * di-resolve dari `sender_phone` (nullable — pengirim tak dikenal tetap
 * disimpan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_incoming_messages', function (Blueprint $table) {
            $table->string('media_type')->nullable()->after('text');
            $table->string('media_mime')->nullable()->after('media_type');
            $table->string('media_file_path')->nullable()->after('media_mime');
            $table->unsignedBigInteger('media_file_size')->nullable()->after('media_file_path');
            $table->string('media_file_name')->nullable()->after('media_file_size');
            $table->text('media_caption')->nullable()->after('media_file_name');
            $table->string('media_sha256', 64)->nullable()->after('media_caption');
            // Alasan ditolak saat ingest (mime tidak lolos finfo, > cap, dll) —
            // baris tetap dicatat tanpa file, dengan alasan di sini.
            $table->string('media_rejected_reason')->nullable()->after('media_sha256');
            $table->foreignId('customer_id')->nullable()->after('reseller_id')->constrained('customers')->nullOnDelete();

            $table->index('media_type');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_incoming_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropIndex(['media_type']);
            $table->dropColumn([
                'media_type',
                'media_mime',
                'media_file_path',
                'media_file_size',
                'media_file_name',
                'media_caption',
                'media_sha256',
                'media_rejected_reason',
            ]);
        });
    }
};
