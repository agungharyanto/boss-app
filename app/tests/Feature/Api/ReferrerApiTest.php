<?php

namespace Tests\Feature\Api;

use App\Enums\ReferrerType;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferrerApiTest extends TestCase
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

    public function test_admin_can_create_a_referrer_without_a_login_account(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/referrers', [
            'name' => 'Budi Sales',
            'phone' => '081111111111',
            'type' => ReferrerType::Sales->value,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.referrer.name', 'Budi Sales');
        $response->assertJsonPath('data.referrer.has_login_account', false);
        $response->assertJsonPath('data.generated_password', null);
        $this->assertDatabaseHas('referrers', ['name' => 'Budi Sales', 'user_id' => null]);
    }

    public function test_admin_can_create_a_referrer_with_a_login_account_and_password_is_shown_once(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/referrers', [
            'name' => 'Siti Freelance',
            'phone' => '081111111112',
            'type' => ReferrerType::Freelance->value,
            'create_login_account' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.referrer.has_login_account', true);
        $generatedPassword = $response->json('data.generated_password');
        $this->assertNotEmpty($generatedPassword);

        $referrer = Referrer::where('name', 'Siti Freelance')->first();
        $this->assertNotNull($referrer->user_id);
        $this->assertNotNull($referrer->user);
        // The generated User gets no Spatie role at all — never able to
        // reach the admin panel, only the referrer portal.
        $this->assertCount(0, $referrer->user->roles);
    }

    public function test_phone_must_be_unique_within_the_same_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        Referrer::factory()->create(['tenant_id' => $tenant->id, 'phone' => '081111111113']);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/referrers', [
            'name' => 'Duplikat',
            'phone' => '081111111113',
            'type' => ReferrerType::Sales->value,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['phone']);
    }

    public function test_same_phone_is_allowed_across_different_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        Referrer::factory()->create(['tenant_id' => $tenantA->id, 'phone' => '081111111114']);

        $response = $this->actingAs($this->admin($tenantB))->postJson('/api/v1/referrers', [
            'name' => 'Beda Tenant',
            'phone' => '081111111114',
            'type' => ReferrerType::Sales->value,
        ]);

        $response->assertCreated();
    }

    public function test_admin_can_update_a_referrer(): void
    {
        $tenant = Tenant::factory()->create();
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Nama Lama']);

        $response = $this->actingAs($this->admin($tenant))->putJson("/api/v1/referrers/{$referrer->id}", [
            'name' => 'Nama Baru',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Nama Baru');
    }

    public function test_admin_can_deactivate_a_referrer(): void
    {
        $tenant = Tenant::factory()->create();
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);

        $response = $this->actingAs($this->admin($tenant))->postJson("/api/v1/referrers/{$referrer->id}/deactivate");

        $response->assertOk();
        $this->assertDatabaseHas('referrers', ['id' => $referrer->id, 'is_active' => false]);
    }

    public function test_admin_can_generate_a_login_account_for_an_existing_referrer(): void
    {
        $tenant = Tenant::factory()->create();
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id, 'user_id' => null]);

        $response = $this->actingAs($this->admin($tenant))->postJson("/api/v1/referrers/{$referrer->id}/generate-login-account");

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.generated_password'));
        $this->assertNotNull($referrer->fresh()->user_id);
    }

    public function test_generating_a_login_account_twice_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $existingUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $existingUser->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson("/api/v1/referrers/{$referrer->id}/generate-login-account");

        $response->assertUnprocessable();
    }

    public function test_admin_can_link_an_existing_user_as_a_referrers_login_account(): void
    {
        $tenant = Tenant::factory()->create();
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id, 'user_id' => null]);
        $freeUser = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson("/api/v1/referrers/{$referrer->id}/link-user", [
            'user_id' => $freeUser->id,
        ]);

        $response->assertOk();
        $this->assertSame($freeUser->id, $referrer->fresh()->user_id);
    }

    public function test_linking_a_user_already_linked_to_another_referrer_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $linkedUser = User::factory()->create(['tenant_id' => $tenant->id]);
        Referrer::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $linkedUser->id]);
        $secondReferrer = Referrer::factory()->create(['tenant_id' => $tenant->id, 'user_id' => null]);

        $response = $this->actingAs($this->admin($tenant))->postJson("/api/v1/referrers/{$secondReferrer->id}/link-user", [
            'user_id' => $linkedUser->id,
        ]);

        $response->assertUnprocessable();
        $this->assertNull($secondReferrer->fresh()->user_id);
    }

    public function test_a_role_without_referrers_permission_cannot_list_referrers(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->nonAdminStaff($tenant))->getJson('/api/v1/referrers');

        $response->assertForbidden();
    }

    public function test_referrers_from_another_tenant_are_not_visible(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        Referrer::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Tenant A Referrer']);
        Referrer::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Tenant B Referrer']);

        $response = $this->actingAs($this->admin($tenantA))->getJson('/api/v1/referrers');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Tenant A Referrer');
    }
}
