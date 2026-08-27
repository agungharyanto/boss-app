<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.14.2 — cluster "Profil Paket". A GENUINELY DIFFERENT concept from
 * VpnIpPool (v0.8.1, tunnel IP pool between a NAS and BOSS App itself) —
 * this table is IP ranges allocated to a NAS's own end-CUSTOMER devices
 * (hotspot/PPP), the "Modul IP Pool" a Grup Profil (v0.14.3) will pick from.
 * Named explicitly CustomerIpPool/customer_ip_pools (not just "IpPool") so
 * this distinction never gets confused later — see CLAUDE.md's own
 * "Cluster Profil Paket" governance note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_ip_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // A customer IP pool makes no sense without a real NAS behind
            // it (there's no physical device to actually serve these IPs
            // otherwise) — restrictOnDelete, not cascade: deleting a NAS
            // that still has pools attached must be an explicit, deliberate
            // action, never a silent side effect of removing the NAS row.
            $table->foreignId('nas_id')->constrained('nas')->restrictOnDelete();
            $table->string('name');
            $table->string('network_address');
            $table->string('gateway_ip');
            $table->string('range_start');
            $table->string('range_end');
            $table->string('dns_primary')->nullable();
            $table->string('dns_secondary')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('nas_id');
        });

        // name unik PER NAS, bukan per tenant global — dua NAS berbeda
        // boleh masing-masing punya pool bernama sama (mis. "Pool Utama").
        // Partial (WHERE deleted_at IS NULL) supaya nama pool yang sudah
        // di-soft-delete bisa dipakai ulang — sama pola dengan
        // bandwidth_profiles_tenant_id_name_unique di atasnya, portable ke
        // PostgreSQL maupun SQLite/phpunit.
        DB::statement(
            'CREATE UNIQUE INDEX customer_ip_pools_nas_id_name_unique '.
            'ON customer_ip_pools (nas_id, name) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ip_pools');
    }
};
