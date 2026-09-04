<?php

namespace Tests\Feature\Api;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\NetworkProfileGroupType;
use App\Enums\RegistrationChannel;
use App\Models\BandwidthProfile;
use App\Models\CommissionRate;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function package(int $tenantId): PppPackage
    {
        $group = NetworkProfileGroup::factory()->create([
            'tenant_id' => $tenantId,
            'type' => NetworkProfileGroupType::Ppp,
        ]);

        return PppPackage::factory()->create([
            'tenant_id' => $tenantId,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $tenantId])->id,
        ]);
    }

    public function test_sales_internal_can_register_a_customer_without_a_referrer(): void
    {
        $user = $this->userWithRole('sales_internal');

        $response = $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Budi Santoso',
            'address' => 'Jl. Merdeka No. 1',
            'phone_number' => '081234567890',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Budi Santoso');
        $this->assertDatabaseHas('customers', [
            'name' => 'Budi Santoso',
            'registration_channel' => RegistrationChannel::Admin->value,
        ]);
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_a_user_linked_to_a_referrer_is_auto_attributed_as_the_referrer(): void
    {
        $user = $this->userWithRole('sales_freelance');
        $referrer = Referrer::factory()->freelance()->create(['tenant_id' => $user->tenant_id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Siti Aminah',
            'address' => 'Jl. Melati No. 2',
            'phone_number' => '081234567891',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('customers', [
            'name' => 'Siti Aminah',
            'referred_by_referrer_id' => $referrer->id,
            'registration_channel' => RegistrationChannel::Freelance->value,
        ]);
        $this->assertDatabaseHas('commission_ledger', [
            'referrer_id' => $referrer->id,
            'status' => CommissionStatus::Pending->value,
        ]);
    }

    public function test_a_caller_without_a_linked_referrer_can_optionally_attribute_a_referral(): void
    {
        $user = $this->userWithRole('superadmin');
        $referrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);

        $response = $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Andi Wijaya',
            'address' => 'Jl. Kenanga No. 3',
            'phone_number' => '081234567892',
            'referred_by_referrer_id' => $referrer->id,
        ]);

        $response->assertCreated();
        // Perilaku LAMA (tanpa scheme) tetap: ledger Pending, amount NULL.
        $this->assertDatabaseHas('commission_ledger', [
            'referrer_id' => $referrer->id,
            'status' => CommissionStatus::Pending->value,
            'amount' => null,
            'scheme' => null,
        ]);
    }

    /**
     * Perilaku BARU (v0.9.4) — ppp_package_id + referrer + scheme:
     * commission_ledger.amount + scheme terisi dari commission_rates.
     */
    public function test_registering_with_ppp_package_and_scheme_fills_commission_amount(): void
    {
        $user = $this->userWithRole('superadmin');
        $referrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);
        $package = $this->package($user->tenant_id);
        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => 25000,
            'limited_count_amount' => null,
            'limited_count_times' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Andi Komisi',
            'address' => 'Jl. Kenanga No. 9',
            'phone_number' => '081234567899',
            'referred_by_referrer_id' => $referrer->id,
            'ppp_package_id' => $package->id,
            'scheme' => CommissionScheme::Recurring->value,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('customers', [
            'name' => 'Andi Komisi',
            'ppp_package_id' => $package->id,
        ]);
        $this->assertDatabaseHas('commission_ledger', [
            'referrer_id' => $referrer->id,
            'status' => CommissionStatus::Pending->value,
            'scheme' => CommissionScheme::Recurring->value,
            'amount' => '25000.00',
        ]);
    }

    public function test_an_invalid_scheme_value_is_rejected(): void
    {
        $user = $this->userWithRole('superadmin');

        $response = $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Salah Skema',
            'address' => 'Jl. Kenanga No. 10',
            'phone_number' => '081234567810',
            'scheme' => 'titip',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['scheme']);
    }

    public function test_ppp_package_from_another_tenant_is_rejected(): void
    {
        $user = $this->userWithRole('superadmin');
        $other = $this->userWithRole('superadmin');
        $foreignPackage = $this->package($other->tenant_id);

        $response = $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Paket Asing',
            'address' => 'Jl. Kenanga No. 11',
            'phone_number' => '081234567811',
            'ppp_package_id' => $foreignPackage->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['ppp_package_id']);
    }

    public function test_role_without_register_customer_permission_is_forbidden(): void
    {
        $user = $this->userWithRole('billing');

        $response = $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Budi Santoso',
            'address' => 'Jl. Merdeka No. 1',
            'phone_number' => '081234567890',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('customers', ['name' => 'Budi Santoso']);
    }

    public function test_duplicate_nik_within_the_same_tenant_is_rejected(): void
    {
        $user = $this->userWithRole('sales_internal');

        $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Ahmad Saefulloh',
            'address' => 'Jl. Merdeka No. 1',
            'phone_number' => '081234567801',
            'nik' => '3201012501990001',
        ])->assertCreated();

        $response = $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Nama Lain',
            'address' => 'Jl. Merdeka No. 2',
            'phone_number' => '081234567802',
            'nik' => '3201012501990001',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['nik']);
    }

    public function test_same_nik_is_allowed_across_different_tenants(): void
    {
        $userA = $this->userWithRole('sales_internal');
        $userB = $this->userWithRole('sales_internal');

        $this->actingAs($userA)->postJson('/api/v1/registrations', [
            'name' => 'Pelanggan Tenant A',
            'address' => 'Jl. Merdeka No. 1',
            'phone_number' => '081234567803',
            'nik' => '3201012501990001',
        ])->assertCreated();

        $response = $this->actingAs($userB)->postJson('/api/v1/registrations', [
            'name' => 'Pelanggan Tenant B',
            'address' => 'Jl. Merdeka No. 2',
            'phone_number' => '081234567804',
            'nik' => '3201012501990001',
        ]);

        $response->assertCreated();
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $user = $this->userWithRole('sales_internal');

        $response = $this->actingAs($user)->postJson('/api/v1/registrations', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name', 'address', 'phone_number']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/v1/registrations', [])->assertUnauthorized();
    }

    public function test_a_referrer_sees_their_own_referrals_with_commission_status(): void
    {
        $user = $this->userWithRole('sales_freelance');
        $referrer = Referrer::factory()->freelance()->create(['tenant_id' => $user->tenant_id, 'user_id' => $user->id]);

        $this->actingAs($user)->postJson('/api/v1/registrations', [
            'name' => 'Rina Kusuma',
            'address' => 'Jl. Anggrek No. 4',
            'phone_number' => '081234567893',
        ])->assertCreated();

        $response = $this->actingAs($user)->getJson('/api/v1/referrals');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.customer_name', 'Rina Kusuma');
        // v0.9.6 — resource sekarang mengembalikan SEMUA baris komisi
        // (commissions[]), bukan satu commission_status tunggal.
        $response->assertJsonCount(1, 'data.0.commissions');
        $response->assertJsonPath('data.0.commissions.0.status', CommissionStatus::Pending->value);
    }

    public function test_a_caller_with_permission_but_no_linked_referrer_gets_not_found(): void
    {
        $user = $this->userWithRole('sales_internal');

        $this->actingAs($user)->getJson('/api/v1/referrals')->assertNotFound();
    }

    public function test_role_without_register_customer_permission_cannot_view_referrals(): void
    {
        $user = $this->userWithRole('billing');

        $this->actingAs($user)->getJson('/api/v1/referrals')->assertForbidden();
    }
}
