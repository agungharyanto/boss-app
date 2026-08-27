<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.14.1 — fondasi cluster "Profil Paket". upload_min/upload_max/
 * download_min/download_max disimpan dalam Kbps (integer) secara internal,
 * terlepas satuan yang dipilih user saat input (Kbps/Mbps) — konversi
 * terjadi di layer form/service (App\Livewire\Network\BandwidthProfileIndex/
 * App\Services\Network\BandwidthProfileService), bukan disimpan sebagai raw
 * value + kolom unit terpisah, supaya query/sort/compare tetap konsisten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bandwidth_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('upload_min');
            $table->unsignedInteger('upload_max');
            $table->unsignedInteger('download_min');
            $table->unsignedInteger('download_max');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
        });

        // name unik per tenant, tapi hanya di antara baris yang belum
        // soft-deleted — sama pola dengan customer_contacts' partial unique
        // index di atasnya (portable ke PostgreSQL maupun SQLite/phpunit).
        // Tanpa WHERE deleted_at IS NULL, sebuah nama yang sudah dihapus
        // tidak akan pernah bisa dipakai ulang.
        DB::statement(
            'CREATE UNIQUE INDEX bandwidth_profiles_tenant_id_name_unique '.
            'ON bandwidth_profiles (tenant_id, name) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('bandwidth_profiles');
    }
};
