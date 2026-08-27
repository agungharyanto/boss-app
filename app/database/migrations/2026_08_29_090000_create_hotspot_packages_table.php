<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.14.4 — cluster "Profil Paket". A sellable voucher/token package
 * (catalog entry, not a per-customer/per-voucher row — that's a later
 * sub-version), referencing an existing NetworkProfileGroup (v0.14.3,
 * MUST be type=hotspot — enforced in Store/UpdateHotspotPackageRequest,
 * not at the DB level, since Postgres has no portable "check this FK row's
 * own column value" constraint) and BandwidthProfile (v0.14.1).
 *
 * Deliberately built as a STANDALONE entity this sub-version, NOT wired to
 * reseller_package_pricing (v0.3.2) — see CLAUDE.md's own v0.14.4
 * investigation section for the full reasoning: reseller_package_pricing
 * is a recurring-SUBSCRIPTION pricing concept (feeds Subscription/Invoice),
 * genuinely different from a hotspot voucher's pay-per-token model;
 * docs/ROADMAP.md's own v0.14.5 entry already earmarks Profil PPP, not
 * Profil Hotspot, as reseller_package_pricing's eventual replacement.
 * visible_to_reseller is therefore a plain boolean this sub-version, not a
 * relation into the reseller pricing system.
 *
 * mikrotik_profile_name (NOT in the original sprint spec, added after a
 * real Langkah-0 finding): `/ip hotspot user profile` rejects `comment` as
 * a parameter entirely (confirmed via a live add/set round trip against
 * ro-hotspot.bajastu.id — "unknown parameter comment"), unlike `/ppp
 * profile`/`/ip pool`, both of which support it and already use it as
 * their stable lookup key (see NetworkProfileGroup::mikrotikComment()/
 * CustomerIpPool::mikrotikComment()). Without a way to track "what name was
 * this pushed as last time", renaming a HotspotPackage in BOSS App would
 * silently create a NEW `/ip hotspot user profile` object under the new
 * name, orphaning the old one — the same class of stale-object bug this
 * codebase has hit and fixed for real several times already (see CLAUDE.md's
 * "Infra Tunnel IP Block" section's `/ip address`/`/ip route` staleness
 * writeup). This column records the name actually pushed to the router on
 * the last successful sync, so a rename can look the old object up first
 * (by mikrotik_profile_name) and rename it in place via `/set name=...`
 * rather than leaving an orphan — see HotspotPackage::mikrotikLookupName().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotspot_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete — same reasoning as NetworkProfileGroup's own
            // nas_id/customer_ip_pool_id FKs: a package still referencing a
            // group/profile must not be silently orphaned by their deletion.
            $table->foreignId('network_profile_group_id')->constrained('network_profile_groups')->restrictOnDelete();
            $table->foreignId('bandwidth_profile_id')->constrained('bandwidth_profiles')->restrictOnDelete();
            $table->string('name');
            $table->boolean('visible_to_reseller')->default(false);
            $table->boolean('show_in_voucher_form')->default(false);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('sell_price', 12, 2)->default(0);
            $table->decimal('promo_price', 12, 2)->nullable();
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->string('profile_type')->default('unlimited');
            $table->string('limit_type')->nullable();
            $table->unsignedInteger('active_duration_value')->nullable();
            $table->string('active_duration_unit')->nullable();
            $table->unsignedInteger('shared_users')->default(1);
            $table->string('priority')->default('Default');
            // json array of English day-name strings (monday..sunday) — a
            // null/empty value means "semua hari" (every day, unrestricted),
            // see HotspotPackage::loginDaysLabel().
            $table->json('login_days')->nullable();
            $table->time('login_start_time')->nullable();
            $table->time('login_end_time')->nullable();
            $table->boolean('is_active')->default(true);
            // mikrotik_sync_* — same pattern as CustomerIpPool/
            // NetworkProfileGroup, never part of a FormRequest's validated()
            // output, only written by HotspotPackage::markSync*().
            $table->string('mikrotik_sync_status')->default('pending');
            $table->timestamp('mikrotik_synced_at')->nullable();
            $table->text('mikrotik_sync_error')->nullable();
            $table->string('mikrotik_profile_name')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('network_profile_group_id');
            $table->index('bandwidth_profile_id');
        });

        // name unik PER Grup Profil (network_profile_group_id) — dua Grup
        // Profil berbeda boleh masing-masing punya Profil Hotspot bernama
        // sama, sama pola dengan customer_ip_pools (unik per NAS) dan
        // network_profile_groups (unik per NAS).
        DB::statement(
            'CREATE UNIQUE INDEX hotspot_packages_group_id_name_unique '.
            'ON hotspot_packages (network_profile_group_id, name) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('hotspot_packages');
    }
};
