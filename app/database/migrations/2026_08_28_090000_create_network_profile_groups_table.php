<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.14.3 — cluster "Profil Paket". A NAS-scoped RADIUS/Mikrotik profile
 * template (type Hotspot or PPP), referencing an existing CustomerIpPool
 * (v0.14.2) from the SAME nas_id, used starting v0.14.4/v0.14.5 (Profil
 * Hotspot/Profil PPP) as a selectable reference. Named "NetworkProfileGroup"
 * (kept as originally proposed — no compelling reason found to rename,
 * matches the existing "Network" namespacing convention already used for
 * Nas/CustomerIpPool/OltDevice throughout App\Services and App\Livewire).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_profile_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nas_id')->constrained('nas')->restrictOnDelete();
            $table->string('name');
            $table->string('type');
            // restrictOnDelete: a CustomerIpPool still referenced by a
            // NetworkProfileGroup must not disappear out from under it —
            // same reasoning as nas_id above.
            $table->foreignId('customer_ip_pool_id')->constrained('customer_ip_pools')->restrictOnDelete();
            $table->string('dns_primary')->nullable();
            $table->string('dns_secondary')->nullable();
            $table->string('parent_queue')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('mikrotik_sync_status')->default('pending');
            $table->timestamp('mikrotik_synced_at')->nullable();
            $table->text('mikrotik_sync_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('nas_id');
            $table->index('customer_ip_pool_id');
        });

        // name unik PER NAS, sama pola dengan customer_ip_pools — dua NAS
        // berbeda boleh masing-masing punya Grup Profil bernama sama.
        DB::statement(
            'CREATE UNIQUE INDEX network_profile_groups_nas_id_name_unique '.
            'ON network_profile_groups (nas_id, name) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('network_profile_groups');
    }
};
