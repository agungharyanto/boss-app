<?php

namespace Tests\Feature\Customers;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\NetworkProfileGroupType;
use App\Livewire\Customers\RegisterCustomer;
use App\Models\BandwidthProfile;
use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Installation\WorkOrderService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.26.2c — Registrasi Pelanggan jadi satu pintu: Customer + Subscription +
 * WorkOrder dibuat dalam satu transaksi lewat RegistrationService::register().
 * ppp_package_id sekarang WAJIB (dulu opsional/dead sejak v0.9.4).
 */
class RegisterCustomerLivewireTest extends TestCase
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

    public function test_duplicate_nik_is_rejected_via_the_livewire_registration_form(): void
    {
        $user = $this->userWithRole('sales_internal');
        $package = $this->package($user->tenant_id);

        Customer::factory()->create([
            'tenant_id' => $user->tenant_id,
            'nik' => '3201012501990001',
        ]);

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('name', 'Nama Lain')
            ->set('address', 'Jl. Merdeka No. 2')
            ->set('phone_number', '081234567899')
            ->set('nik', '3201012501990001')
            ->set('ppp_package_id', $package->id)
            ->call('register')
            ->assertHasErrors(['nik']);

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_a_fresh_nik_is_accepted_via_the_livewire_registration_form(): void
    {
        $user = $this->userWithRole('sales_internal');
        $package = $this->package($user->tenant_id);

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('name', 'Pelanggan Baru')
            ->set('address', 'Jl. Merdeka No. 3')
            ->set('phone_number', '081234567898')
            ->set('nik', '3201012501990002')
            ->set('ppp_package_id', $package->id)
            ->call('register')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', ['name' => 'Pelanggan Baru']);
    }

    public function test_scheme_dropdown_only_offers_options_available_on_the_rate(): void
    {
        $user = $this->userWithRole('superadmin');
        $referrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);
        $package = $this->package($user->tenant_id);
        // Rate hanya punya recurring_amount — 'X-Kali' TIDAK boleh muncul.
        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => 15000,
            'limited_count_amount' => null,
            'limited_count_times' => null,
        ]);

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('ppp_package_id', $package->id)
            ->set('selectedReferrerId', $referrer->id)
            ->assertViewHas('showSchemeField', true)
            ->assertViewHas('schemeOptions', ['recurring' => 'Per Bulan - Rp 15.000']);
    }

    public function test_scheme_labels_include_the_rupiah_amount_from_the_rate(): void
    {
        $user = $this->userWithRole('superadmin');
        $referrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);
        $package = $this->package($user->tenant_id);
        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => 3000,
            'limited_count_amount' => 33000,
            'limited_count_times' => 2,
        ]);

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('ppp_package_id', $package->id)
            ->set('selectedReferrerId', $referrer->id)
            ->assertViewHas('schemeOptions', [
                'recurring' => 'Per Bulan - Rp 3.000',
                'limited_count' => '2 Kali - Rp 33.000',
            ])
            ->assertSeeInOrder(['Per Bulan - Rp 3.000', '2 Kali - Rp 33.000']);
    }

    public function test_scheme_field_hidden_without_a_referrer_or_a_rate(): void
    {
        $user = $this->userWithRole('superadmin');
        $package = $this->package($user->tenant_id);
        CommissionRate::factory()->create(['ppp_package_id' => $package->id, 'recurring_amount' => 15000]);

        $this->actingAs($user);

        // Paket dipilih, rate ada, TAPI belum ada referrer → field tidak muncul.
        Livewire::test(RegisterCustomer::class)
            ->set('ppp_package_id', $package->id)
            ->assertViewHas('showSchemeField', false);
    }

    public function test_registering_with_referrer_package_and_scheme_creates_ledger_with_amount(): void
    {
        $user = $this->userWithRole('superadmin');
        $referrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);
        $package = $this->package($user->tenant_id);
        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'limited_count_amount' => 30000,
            'limited_count_times' => 2,
            'recurring_amount' => null,
        ]);

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('name', 'Pelanggan Skema')
            ->set('address', 'Jl. Skema No. 1')
            ->set('phone_number', '081200000001')
            ->set('ppp_package_id', $package->id)
            ->set('selectedReferrerId', $referrer->id)
            ->set('commissionScheme', CommissionScheme::LimitedCount->value)
            ->call('register')
            ->assertHasNoErrors();

        $customer = Customer::where('name', 'Pelanggan Skema')->firstOrFail();
        $this->assertSame($package->id, $customer->ppp_package_id);
        $this->assertDatabaseHas('commission_ledger', [
            'customer_id' => $customer->id,
            'referrer_id' => $referrer->id,
            'scheme' => CommissionScheme::LimitedCount->value,
            'amount' => '30000.00',
            'status' => CommissionStatus::Pending->value,
        ]);
    }

    public function test_registering_with_referrer_but_no_scheme_keeps_amount_null(): void
    {
        $user = $this->userWithRole('superadmin');
        $referrer = Referrer::factory()->create(['tenant_id' => $user->tenant_id]);
        $package = $this->package($user->tenant_id);
        CommissionRate::factory()->create(['ppp_package_id' => $package->id, 'recurring_amount' => 15000]);

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('name', 'Pelanggan Skip Skema')
            ->set('address', 'Jl. Skema No. 2')
            ->set('phone_number', '081200000002')
            ->set('ppp_package_id', $package->id)
            ->set('selectedReferrerId', $referrer->id)
            // commissionScheme sengaja tidak di-set
            ->call('register')
            ->assertHasNoErrors();

        $customer = Customer::where('name', 'Pelanggan Skip Skema')->firstOrFail();
        $this->assertDatabaseHas('commission_ledger', [
            'customer_id' => $customer->id,
            'scheme' => null,
            'amount' => null,
        ]);
    }

    // ── v0.26.2c — satu pintu: Customer + Subscription + WorkOrder ─────

    public function test_registering_without_a_package_is_rejected_and_creates_nothing(): void
    {
        $user = $this->userWithRole('sales_internal');

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('name', 'Tanpa Paket')
            ->set('address', 'Jl. Tanpa Paket No. 1')
            ->set('phone_number', '081200000099')
            ->call('register')
            ->assertHasErrors(['ppp_package_id']);

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_registering_with_a_scheduled_visit_creates_a_scheduled_work_order(): void
    {
        $user = $this->userWithRole('sales_internal');
        $package = $this->package($user->tenant_id);

        $this->actingAs($user);

        $scheduledAt = now()->addDays(2)->format('Y-m-d\TH:i');

        Livewire::test(RegisterCustomer::class)
            ->set('name', 'Pelanggan Janji')
            ->set('address', 'Jl. Janji No. 1')
            ->set('phone_number', '081200000010')
            ->set('ppp_package_id', $package->id)
            ->set('scheduledVisitAt', $scheduledAt)
            ->call('register')
            ->assertHasNoErrors();

        $customer = Customer::where('name', 'Pelanggan Janji')->firstOrFail();
        $subscription = Subscription::withoutGlobalScopes()->where('customer_id', $customer->id)->firstOrFail();
        $workOrder = WorkOrder::withoutGlobalScopes()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame($package->name, $subscription->name);
        $this->assertSame((float) $package->sell_price, (float) $subscription->monthly_amount);
        $this->assertSame(min(now()->day, 28), $subscription->billing_cycle_day);
        $this->assertNotNull($workOrder->scheduled_at);
        $this->assertNull($workOrder->dispatched_at);
    }

    public function test_registering_without_a_scheduled_visit_dispatches_the_work_order_immediately(): void
    {
        $user = $this->userWithRole('sales_internal');
        $package = $this->package($user->tenant_id);

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('name', 'Pelanggan Segera')
            ->set('address', 'Jl. Segera No. 1')
            ->set('phone_number', '081200000011')
            ->set('ppp_package_id', $package->id)
            ->call('register')
            ->assertHasNoErrors();

        $customer = Customer::where('name', 'Pelanggan Segera')->firstOrFail();
        $workOrder = WorkOrder::withoutGlobalScopes()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertNull($workOrder->scheduled_at);
        $this->assertNotNull($workOrder->dispatched_at);
    }

    public function test_a_past_scheduled_visit_is_rejected(): void
    {
        $user = $this->userWithRole('sales_internal');
        $package = $this->package($user->tenant_id);

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('name', 'Pelanggan Lampau')
            ->set('address', 'Jl. Lampau No. 1')
            ->set('phone_number', '081200000012')
            ->set('ppp_package_id', $package->id)
            ->set('scheduledVisitAt', now()->subDay()->format('Y-m-d\TH:i'))
            ->call('register')
            ->assertHasErrors(['scheduledVisitAt']);

        $this->assertDatabaseMissing('customers', ['name' => 'Pelanggan Lampau']);
    }

    public function test_a_failure_creating_the_work_order_rolls_back_the_whole_registration(): void
    {
        $user = $this->userWithRole('sales_internal');
        $package = $this->package($user->tenant_id);

        $this->mock(WorkOrderService::class, function ($mock) {
            $mock->shouldReceive('createFromSubscription')->andThrow(new \RuntimeException('simulated failure'));
        });

        $this->actingAs($user);

        try {
            Livewire::test(RegisterCustomer::class)
                ->set('name', 'Pelanggan Gagal')
                ->set('address', 'Jl. Gagal No. 1')
                ->set('phone_number', '081200000013')
                ->set('ppp_package_id', $package->id)
                ->call('register');

            $this->fail('Expected the simulated WorkOrderService failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }

        // Rollback penuh — Customer, commission_ledger (kalau ada), dan
        // Subscription TIDAK ADA sama sekali, bukan tersisa setengah jadi.
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }
}
