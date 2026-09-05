<?php

namespace Tests\Feature\Api;

use App\Enums\NetworkProfileGroupType;
use App\Models\BandwidthProfile;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BandwidthProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    private function nonAdminStaff(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        return $user;
    }

    public function test_admin_can_create_a_bandwidth_profile(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/bandwidth-profiles', [
            'name' => '10 Mbps',
            'upload_min' => 5000,
            'upload_max' => 10000,
            'download_min' => 5000,
            'download_max' => 10000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', '10 Mbps');
        $response->assertJsonPath('data.upload_max_display', '10 Mbps');
        $this->assertDatabaseHas('bandwidth_profiles', ['name' => '10 Mbps', 'tenant_id' => $tenant->id]);
    }

    public function test_upload_max_must_be_gte_upload_min(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/bandwidth-profiles', [
            'name' => 'Invalid',
            'upload_min' => 10000,
            'upload_max' => 5000,
            'download_min' => 5000,
            'download_max' => 10000,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['upload_max']);
    }

    public function test_download_max_must_be_gte_download_min(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/bandwidth-profiles', [
            'name' => 'Invalid',
            'upload_min' => 5000,
            'upload_max' => 10000,
            'download_min' => 10000,
            'download_max' => 5000,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['download_max']);
    }

    public function test_name_must_be_unique_within_the_same_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        BandwidthProfile::factory()->create(['tenant_id' => $tenant->id, 'name' => '10 Mbps']);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/bandwidth-profiles', [
            'name' => '10 Mbps',
            'upload_min' => 5000,
            'upload_max' => 10000,
            'download_min' => 5000,
            'download_max' => 10000,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);
    }

    /**
     * FIX 4 (aturan nama final 2026-09-05) — Bandwidth Profile BEBAS: nama
     * boleh sama dengan Grup Profil / Profil PPP / Profil Hotspot apa pun.
     * BandwidthProfile tidak pernah push objek RouterOS bernama (dia
     * di-embed ke string rate-limit), jadi tidak ada namespace collision
     * sama sekali — hanya keunikan antar-BandwidthProfile per-tenant yang
     * berlaku (dites di atas).
     */
    public function test_bandwidth_profile_name_may_match_a_grup_profil_or_ppp_package_name(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create([
            'nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id,
            'type' => NetworkProfileGroupType::Ppp, 'name' => 'HomeFixed-10Mbps',
        ]);
        PppPackage::factory()->create(['network_profile_group_id' => $group->id, 'name' => 'HomeFixed-10Mbps']);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/bandwidth-profiles', [
            'name' => 'HomeFixed-10Mbps',
            'upload_min' => 5000,
            'upload_max' => 10000,
            'download_min' => 5000,
            'download_max' => 10000,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('bandwidth_profiles', ['name' => 'HomeFixed-10Mbps', 'tenant_id' => $tenant->id]);
    }

    /**
     * Real bug found via manual UI testing (v0.14.1): two ACTIVE profiles
     * named "10Mbps", side by side, both passed validation — neither
     * soft-deleted, created 18 seconds apart (not a race condition).
     * Root cause: one had a trailing space ("10Mbps "), a byte-distinct
     * string Rule::unique() correctly never flagged. This test reproduces
     * the exact scenario without any whitespace trick at all — same name,
     * same tenant, neither row soft-deleted — to prove the base case (which
     * whereNull('deleted_at') was never supposed to break) still rejects.
     */
    public function test_exact_duplicate_name_is_rejected_when_neither_row_is_soft_deleted(): void
    {
        $tenant = Tenant::factory()->create();
        BandwidthProfile::factory()->create(['tenant_id' => $tenant->id, 'name' => '10Mbps']);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/bandwidth-profiles', [
            'name' => '10Mbps',
            'upload_min' => 5000,
            'upload_max' => 10000,
            'download_min' => 5000,
            'download_max' => 10000,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);
        $this->assertSame(1, BandwidthProfile::withTrashed()->where('tenant_id', $tenant->id)->where('name', '10Mbps')->count());
    }

    /**
     * The actual real-world reproduction: a trailing space is trimmed
     * before Rule::unique() runs, so "10Mbps " collides with an existing
     * "10Mbps" and is correctly rejected — not silently stored as a
     * byte-distinct, visually-indistinguishable duplicate.
     */
    public function test_name_with_trailing_whitespace_is_trimmed_and_rejected_as_a_duplicate(): void
    {
        $tenant = Tenant::factory()->create();
        BandwidthProfile::factory()->create(['tenant_id' => $tenant->id, 'name' => '10Mbps']);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/bandwidth-profiles', [
            'name' => '10Mbps ',
            'upload_min' => 5000,
            'upload_max' => 10000,
            'download_min' => 5000,
            'download_max' => 10000,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);
    }

    /**
     * A name that's only whitespace-different from an existing one, but
     * genuinely unique after trimming, is still stored trimmed — proves
     * the fix normalizes on the accepted path too, not just the rejected
     * one.
     */
    public function test_name_with_leading_and_trailing_whitespace_is_trimmed_before_storing(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/bandwidth-profiles', [
            'name' => '  20Mbps  ',
            'upload_min' => 5000,
            'upload_max' => 10000,
            'download_min' => 5000,
            'download_max' => 10000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', '20Mbps');
        $this->assertDatabaseHas('bandwidth_profiles', ['name' => '20Mbps', 'tenant_id' => $tenant->id]);
    }

    /**
     * Explicit confirmation requested after the trailing-space fix: trim()
     * (used at all 4 write-path call sites) must ONLY strip leading/
     * trailing whitespace, never whitespace INSIDE the name — "15 Mbps"
     * (a space between the number and unit) must be stored exactly as
     * typed, not collapsed into "15Mbps". PHP's trim() already only ever
     * touches the start/end of a string by definition, but this proves it
     * for real rather than assuming it from reading the fix.
     */
    public function test_internal_whitespace_within_the_name_is_preserved_not_trimmed(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/bandwidth-profiles', [
            'name' => '15 Mbps',
            'upload_min' => 5000,
            'upload_max' => 10000,
            'download_min' => 5000,
            'download_max' => 10000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', '15 Mbps');
        $this->assertDatabaseHas('bandwidth_profiles', ['name' => '15 Mbps', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('bandwidth_profiles', ['name' => '15Mbps', 'tenant_id' => $tenant->id]);
    }

    /**
     * Combines both behaviors in one assertion: leading/trailing space
     * stripped, internal space kept — " 15 Mbps " -> "15 Mbps", not
     * "15Mbps" and not left unstripped at the edges either.
     */
    public function test_leading_and_trailing_whitespace_trimmed_while_internal_space_is_kept(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/bandwidth-profiles', [
            'name' => '  15 Mbps  ',
            'upload_min' => 5000,
            'upload_max' => 10000,
            'download_min' => 5000,
            'download_max' => 10000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', '15 Mbps');
        $this->assertDatabaseHas('bandwidth_profiles', ['name' => '15 Mbps', 'tenant_id' => $tenant->id]);
    }

    public function test_same_name_is_allowed_across_different_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        BandwidthProfile::factory()->create(['tenant_id' => $tenantA->id, 'name' => '10 Mbps']);

        $response = $this->actingAs($this->admin($tenantB))->postJson('/api/v1/bandwidth-profiles', [
            'name' => '10 Mbps',
            'upload_min' => 5000,
            'upload_max' => 10000,
            'download_min' => 5000,
            'download_max' => 10000,
        ]);

        $response->assertCreated();
    }

    public function test_a_soft_deleted_names_name_can_be_reused(): void
    {
        $tenant = Tenant::factory()->create();
        $profile = BandwidthProfile::factory()->create(['tenant_id' => $tenant->id, 'name' => '10 Mbps']);
        $profile->delete();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/bandwidth-profiles', [
            'name' => '10 Mbps',
            'upload_min' => 5000,
            'upload_max' => 10000,
            'download_min' => 5000,
            'download_max' => 10000,
        ]);

        $response->assertCreated();
    }

    public function test_admin_can_update_a_bandwidth_profile(): void
    {
        $tenant = Tenant::factory()->create();
        $profile = BandwidthProfile::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Lama']);

        $response = $this->actingAs($this->admin($tenant))->putJson("/api/v1/bandwidth-profiles/{$profile->id}", [
            'name' => 'Baru',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Baru');
    }

    public function test_admin_can_soft_delete_a_bandwidth_profile(): void
    {
        $tenant = Tenant::factory()->create();
        $profile = BandwidthProfile::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->deleteJson("/api/v1/bandwidth-profiles/{$profile->id}");

        $response->assertOk();
        $this->assertSoftDeleted('bandwidth_profiles', ['id' => $profile->id]);
    }

    public function test_a_role_without_bandwidth_profiles_permission_cannot_list(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->nonAdminStaff($tenant))->getJson('/api/v1/bandwidth-profiles');

        $response->assertForbidden();
    }

    public function test_bandwidth_profiles_from_another_tenant_are_not_visible(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        BandwidthProfile::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Tenant A Profile']);
        BandwidthProfile::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Tenant B Profile']);

        $response = $this->actingAs($this->admin($tenantA))->getJson('/api/v1/bandwidth-profiles');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Tenant A Profile');
    }

    public function test_display_accessor_switches_to_mbps_above_1000_kbps(): void
    {
        $tenant = Tenant::factory()->create();
        $profile = BandwidthProfile::factory()->create([
            'tenant_id' => $tenant->id,
            'upload_min' => 512,
            'upload_max' => 50000,
        ]);

        $response = $this->actingAs($this->admin($tenant))->getJson("/api/v1/bandwidth-profiles/{$profile->id}");

        $response->assertOk();
        $response->assertJsonPath('data.upload_min_display', '512 Kbps');
        $response->assertJsonPath('data.upload_max_display', '50 Mbps');
    }
}
