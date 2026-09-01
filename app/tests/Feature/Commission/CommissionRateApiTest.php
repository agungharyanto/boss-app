<?php

namespace Tests\Feature\Commission;

use App\Enums\NetworkProfileGroupType;
use App\Models\BandwidthProfile;
use App\Models\CommissionRate;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionRateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant, string $role = 'superadmin'): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function package(Tenant $tenant): PppPackage
    {
        // Force the package (and its whole FK chain) onto $tenant.
        $group = NetworkProfileGroup::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => NetworkProfileGroupType::Ppp,
        ]);

        return PppPackage::factory()->create([
            'tenant_id' => $tenant->id,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $tenant->id])->id,
        ]);
    }

    public function test_admin_can_create_a_recurring_only_rate(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/commission-rates', [
            'ppp_package_id' => $package->id,
            'recurring_amount' => 25000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.recurring_amount', '25000.00');
        $response->assertJsonPath('data.limited_count_amount', null);
        $this->assertDatabaseHas('commission_rates', [
            'ppp_package_id' => $package->id,
            'tenant_id' => $tenant->id,
            'recurring_amount' => 25000,
        ]);
    }

    public function test_admin_can_create_a_limited_count_scheme_rate(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/commission-rates', [
            'ppp_package_id' => $package->id,
            'limited_count_amount' => 50000,
            'limited_count_times' => 3,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.limited_count_times', 3);
    }

    public function test_admin_can_create_a_titip_only_rate(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/commission-rates', [
            'ppp_package_id' => $package->id,
            'titip_amount' => 10000,
        ]);

        $response->assertCreated();
    }

    public function test_limited_count_amount_requires_limited_count_times(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/commission-rates', [
            'ppp_package_id' => $package->id,
            'limited_count_amount' => 50000,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['limited_count_times']);
    }

    public function test_limited_count_times_requires_limited_count_amount(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/commission-rates', [
            'ppp_package_id' => $package->id,
            'limited_count_times' => 2,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['limited_count_amount']);
    }

    public function test_at_least_one_amount_scheme_must_be_filled(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/commission-rates', [
            'ppp_package_id' => $package->id,
            'is_active' => true,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['recurring_amount']);
    }

    public function test_a_negative_amount_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/commission-rates', [
            'ppp_package_id' => $package->id,
            'recurring_amount' => -1,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['recurring_amount']);
    }

    public function test_zero_is_a_valid_deliberate_amount(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/commission-rates', [
            'ppp_package_id' => $package->id,
            'recurring_amount' => 0,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.recurring_amount', '0.00');
    }

    public function test_a_package_cannot_have_two_active_rates(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);
        CommissionRate::factory()->create(['ppp_package_id' => $package->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/commission-rates', [
            'ppp_package_id' => $package->id,
            'recurring_amount' => 1000,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['ppp_package_id']);
    }

    public function test_a_package_from_another_tenant_is_rejected(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $packageB = $this->package($tenantB);

        $response = $this->actingAs($this->admin($tenantA))->postJson('/api/v1/commission-rates', [
            'ppp_package_id' => $packageB->id,
            'recurring_amount' => 1000,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['ppp_package_id']);
    }

    public function test_admin_can_update_a_rate(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);
        $rate = CommissionRate::factory()->create(['ppp_package_id' => $package->id, 'recurring_amount' => 1000]);

        $response = $this->actingAs($this->admin($tenant))->putJson("/api/v1/commission-rates/{$rate->id}", [
            'recurring_amount' => 5000,
            'titip_amount' => 2000,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.recurring_amount', '5000.00');
        $response->assertJsonPath('data.titip_amount', '2000.00');
    }

    public function test_update_that_would_leave_the_rate_empty_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);
        $rate = CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => 1000,
            'limited_count_amount' => null,
            'limited_count_times' => null,
            'titip_amount' => null,
        ]);

        $response = $this->actingAs($this->admin($tenant))->putJson("/api/v1/commission-rates/{$rate->id}", [
            'recurring_amount' => null,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['recurring_amount']);
    }

    public function test_admin_can_soft_delete_a_rate_and_recreate_one_for_the_same_package(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);
        $rate = CommissionRate::factory()->create(['ppp_package_id' => $package->id]);

        $this->actingAs($this->admin($tenant))->deleteJson("/api/v1/commission-rates/{$rate->id}")->assertOk();
        $this->assertSoftDeleted('commission_rates', ['id' => $rate->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/commission-rates', [
            'ppp_package_id' => $package->id,
            'recurring_amount' => 9999,
        ]);

        $response->assertCreated();
    }

    public function test_a_role_without_commission_rates_permission_cannot_list(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant, 'customer_service'))->getJson('/api/v1/commission-rates');

        $response->assertForbidden();
    }

    public function test_administrator_tier_role_can_manage(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        $response = $this->actingAs($this->admin($tenant, 'administrator'))->postJson('/api/v1/commission-rates', [
            'ppp_package_id' => $package->id,
            'recurring_amount' => 1234,
        ]);

        $response->assertCreated();
    }

    public function test_a_rate_from_another_tenant_is_not_visible_or_reachable(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $rateB = CommissionRate::factory()->create(['ppp_package_id' => $this->package($tenantB)->id]);
        CommissionRate::factory()->create(['ppp_package_id' => $this->package($tenantA)->id]);

        $list = $this->actingAs($this->admin($tenantA))->getJson('/api/v1/commission-rates');
        $list->assertOk();
        $list->assertJsonCount(1, 'data');

        $this->actingAs($this->admin($tenantA))->getJson("/api/v1/commission-rates/{$rateB->id}")->assertNotFound();
    }

    public function test_validation_message_uses_indonesian_field_labels(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->package($tenant);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/commission-rates', [
            'ppp_package_id' => $package->id,
            'limited_count_times' => 2,
        ]);

        $response->assertUnprocessable();
        $this->assertStringContainsString(
            'Komisi Skema X-Kali',
            $response->json('errors.limited_count_amount.0')
        );
    }
}
