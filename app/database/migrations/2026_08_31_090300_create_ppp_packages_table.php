<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.14.5 — cluster "Profil Paket". Profil PPP: a sellable monthly
 * subscription package (catalog entry), referencing an existing
 * NetworkProfileGroup (v0.14.3, MUST be type=ppp — enforced in
 * Store/UpdatePppPackageRequest, not at the DB level, same reasoning as
 * HotspotPackage's own network_profile_group_id) and BandwidthProfile
 * (v0.14.1).
 *
 * No mikrotik_profile_name column, unlike HotspotPackage — `/ppp profile`
 * genuinely supports `comment` (confirmed via the same live add/set round
 * trip already trusted for Grup Profil's own `/ppp profile` push), so
 * PppPackage::mikrotikComment() (a stable per-row identifier, same pattern
 * as NetworkProfileGroup::mikrotikComment()) is the lookup key — no
 * workaround needed for a limitation that doesn't apply to this RouterOS
 * object type.
 *
 * No profile_type/limit_type/quota_* columns, unlike HotspotPackage — Profil
 * PPP is a plain monthly subscription, not a voucher/token with an
 * Unlimited/Limited toggle; active_duration_value/unit (Masa Aktif) is
 * therefore NOT NULL-able the way HotspotPackage's own is (that one is only
 * meaningful for its Limited+TimeBase combination) — every Profil PPP
 * always has a real Masa Aktif, enforced 'required' in both FormRequests.
 *
 * `(network_profile_group_id, name)` unique index below is only a baseline
 * per-parent safety net, same shape as HotspotPackage's own — it does NOT
 * catch the real collision risk this sub-version is built around: a Profil
 * PPP's OWN `/ppp profile` push shares the exact same RouterOS `/ppp
 * profile` name namespace (scoped per-NAS, not per-Grup-Profil) as every
 * Grup Profil's own bare `/ppp profile` AND every other Profil PPP under a
 * DIFFERENT Grup Profil on the SAME NAS. That cross-table, cross-Grup-
 * Profil, same-NAS uniqueness check can't be expressed as a portable
 * Postgres constraint (no JOIN in a partial unique index) — it's enforced
 * application-side in Store/UpdatePppPackageRequest's own withValidator()
 * and mirrored in PppPackageIndex (Livewire), same "cross-entity rule
 * lives in the validation layer" convention already established throughout
 * this codebase (e.g. NetworkProfileGroup's own pool-same-NAS check).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppp_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_profile_group_id')->constrained('network_profile_groups')->restrictOnDelete();
            $table->foreignId('bandwidth_profile_id')->constrained('bandwidth_profiles')->restrictOnDelete();
            $table->string('name');
            $table->boolean('visible_to_reseller')->default(false);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('sell_price', 12, 2)->default(0);
            $table->decimal('promo_price', 12, 2)->nullable();
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->unsignedInteger('active_duration_value');
            // Reuses HotspotDurationUnit (App\Enums), not a new PppDurationUnit
            // — see PppPackage::routerOsSessionTimeout()'s own docblock for
            // why: the RouterOS session-timeout format conversion (m/h/d
            // suffixes, no native month, 30-day approximation) was already
            // empirically verified once for v0.14.4 and reused verbatim
            // rather than duplicated, confirmed to apply identically to
            // `/ppp profile` via a fresh live test this sub-version.
            $table->string('active_duration_unit');
            $table->unsignedInteger('shared_users')->default(1);
            $table->string('priority')->default('Default');
            $table->json('login_days')->nullable();
            $table->time('login_start_time')->nullable();
            $table->time('login_end_time')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('mikrotik_sync_status')->default('pending');
            $table->timestamp('mikrotik_synced_at')->nullable();
            $table->text('mikrotik_sync_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('network_profile_group_id');
            $table->index('bandwidth_profile_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX ppp_packages_group_id_name_unique '.
            'ON ppp_packages (network_profile_group_id, name) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ppp_packages');
    }
};
