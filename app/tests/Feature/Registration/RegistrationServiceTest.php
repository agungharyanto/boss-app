<?php

namespace Tests\Feature\Registration;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\NetworkProfileGroupType;
use App\Enums\RegistrationChannel;
use App\Models\BandwidthProfile;
use App\Models\CommissionLedger;
use App\Models\CommissionRate;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function package(Tenant $tenant): PppPackage
    {
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

    /**
     * Perilaku LAMA (v0.9.3 & sebelumnya) — referrer dipilih tapi tidak
     * ada skema komisi: ledger Pending dibuat dengan amount NULL. Tetap
     * dipertahankan sebagai coverage backward-compatible.
     */
    public function test_registering_with_a_referrer_but_no_scheme_leaves_amount_null(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);

        $customer = app(RegistrationService::class)->register([
            'name' => 'Pelanggan Via Sales',
            'address' => 'Jl. Sales No. 1',
            'phone_number' => '081377777001',
        ], $referrer);

        $this->assertSame($referrer->id, $customer->referred_by_referrer_id);
        $this->assertSame(RegistrationChannel::Sales, $customer->registration_channel);

        $this->assertDatabaseHas('commission_ledger', [
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'status' => CommissionStatus::Pending->value,
            'amount' => null,
            'scheme' => null,
        ]);
    }

    /**
     * Perilaku BARU (v0.9.4) — referrer + paket + skema 'recurring':
     * commission_ledger.scheme + amount terisi dari commission_rates.
     */
    public function test_registering_with_a_referrer_and_recurring_scheme_fills_amount_from_the_rate(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $package = $this->package($tenant);
        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => 27500,
            'limited_count_amount' => null,
            'limited_count_times' => null,
        ]);

        $customer = app(RegistrationService::class)->register([
            'name' => 'Pelanggan Komisi Recurring',
            'address' => 'Jl. Komisi No. 1',
            'phone_number' => '081377777010',
            'ppp_package_id' => $package->id,
        ], $referrer, CommissionScheme::Recurring->value);

        $this->assertSame($package->id, $customer->ppp_package_id);
        $this->assertDatabaseHas('commission_ledger', [
            'customer_id' => $customer->id,
            'referrer_id' => $referrer->id,
            'status' => CommissionStatus::Pending->value,
            'scheme' => CommissionScheme::Recurring->value,
            'amount' => '27500.00',
        ]);
    }

    public function test_registering_with_a_referrer_and_limited_count_scheme_fills_amount_from_the_rate(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $package = $this->package($tenant);
        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => null,
            'limited_count_amount' => 40000,
            'limited_count_times' => 3,
        ]);

        $customer = app(RegistrationService::class)->register([
            'name' => 'Pelanggan Komisi X-Kali',
            'address' => 'Jl. Komisi No. 2',
            'phone_number' => '081377777011',
            'ppp_package_id' => $package->id,
        ], $referrer, CommissionScheme::LimitedCount->value);

        $this->assertDatabaseHas('commission_ledger', [
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::LimitedCount->value,
            'amount' => '40000.00',
        ]);
    }

    /**
     * Jaring pengaman jalur API/race — skema diminta tapi rate paket tidak
     * punya amount untuk skema itu: ledger tetap dibuat, tapi scheme+amount
     * NULL (tidak error, tidak menebak).
     */
    public function test_scheme_is_ignored_when_the_rate_has_no_amount_for_it(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $package = $this->package($tenant);
        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => 10000,
            'limited_count_amount' => null,
            'limited_count_times' => null,
        ]);

        $customer = app(RegistrationService::class)->register([
            'name' => 'Pelanggan Skema Tak Cocok',
            'address' => 'Jl. Komisi No. 3',
            'phone_number' => '081377777012',
            'ppp_package_id' => $package->id,
        ], $referrer, CommissionScheme::LimitedCount->value);

        $this->assertDatabaseHas('commission_ledger', [
            'customer_id' => $customer->id,
            'scheme' => null,
            'amount' => null,
        ]);
    }

    public function test_registering_without_a_referrer_creates_no_commission_row(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        $customer = app(RegistrationService::class)->register([
            'name' => 'Pelanggan Tanpa Referral',
            'address' => 'Jl. Admin No. 1',
            'phone_number' => '081377777002',
        ]);

        $this->assertNull($customer->referred_by_referrer_id);
        $this->assertSame(RegistrationChannel::Admin, $customer->registration_channel);
        $this->assertSame(0, CommissionLedger::where('customer_id', $customer->id)->count());
    }
}
